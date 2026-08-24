<?php

namespace App\Http\Middleware;

use App\Models\IdempotentRequest;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a write that arrives twice count once.
 *
 * The phone names each write before its first attempt and repeats that
 * name on every retry, so a replay is recognisable. Without this, a
 * receiveTimeout — the request ran, the answer was lost — comes back as
 * a second sale that nothing in the system can tell from a real one.
 *
 * Deliberately not a cache: the answer has to survive a queue that may
 * not drain until the next morning, and it has to be the *same* answer,
 * including the id the first attempt created.
 */
class IdempotentWrites
{
    /** How long a name is remembered. A phone left off overnight still lands. */
    public const REMEMBER_FOR_HOURS = 72;

    /**
     * The verbs worth guarding.
     *
     * A read has nothing to make happen twice, so a key on one is either a
     * client bug or a mistake — and honouring it would be worse than
     * ignoring it: the answer would be pinned for the 72 hours below and
     * the phone would be shown a stale figure with no way to refresh it.
     */
    private const GUARDED = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! in_array($request->method(), self::GUARDED, true)) {
            return $next($request);
        }

        // No key, no protection — and that is the honest default. Older
        // app versions send nothing, and refusing them would take the
        // shop offline the moment this deploys.
        if (blank($key) || ! $request->user()) {
            return $next($request);
        }

        // Not installed here yet, so there is nothing to protect with and
        // the request must go through untouched.
        //
        // This sits before the lookup on purpose. Without it the *read* at
        // the top throws on a missing table, before $next has run, and
        // every write in the API answers 500 — the shop cannot record a
        // sale, a batch or a collection. deploy.sh pulls the code and only
        // *reports* pending migrations, so that window is real and not
        // theoretical.
        //
        // The same shape as SalaryPayment::requestsTableExists(), and for
        // the same reason: on 1405/05/29 a payslip ended in a red error
        // because a hook reached for a table a different feature's
        // migration had not created. Guarding against duplicates must not
        // become the thing that stops the shop writing at all.
        if (! self::tableExists()) {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $key)) {
            return response()->json([
                'success' => false,
                'message' => 'شناسهٔ درخواست نامعتبر است.',
                'errors' => null,
            ], 400);
        }

        $hash = IdempotentRequest::hashBody($request->all());

        if ($seen = IdempotentRequest::where('idempotency_key', $key)->first()) {
            // Same name, different work — or a different person. Both are
            // client bugs, and both are dangerous to guess at: serving
            // either answer picks one of two meanings, and across users it
            // would hand somebody another seller's record as their own.
            // Method included. It was recorded from the start and then not
            // compared, so a DELETE could match a POST's row on path and
            // body alone and be handed the POST's answer without ever
            // running — the caller told a thing was created that in fact
            // was never removed.
            if ($seen->user_id !== $request->user()->id
                || $seen->method !== $request->method()
                || $seen->body_hash !== $hash
                || $seen->path !== $request->path()) {
                return response()->json([
                    'success' => false,
                    'message' => 'این شناسه قبلاً برای درخواست دیگری استفاده شده است.',
                    'errors' => null,
                ], 409);
            }

            return response()->json($seen->response, $seen->status_code)
                ->header('Idempotent-Replay', 'true');
        }

        $response = $next($request);

        // Only successful writes are remembered. A validation failure is
        // not work that was done, and pinning it would make the shop
        // retype under a fresh key to correct a typo.
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return $response;
        }

        $decoded = json_decode($response->getContent(), true);

        if (! is_array($decoded)) {
            return $response;
        }

        try {
            IdempotentRequest::create([
                'idempotency_key' => $key,
                'user_id' => $request->user()->id,
                'method' => $request->method(),
                'path' => $request->path(),
                'body_hash' => $hash,
                'status_code' => $response->getStatusCode(),
                'response' => $decoded,
            ]);
        } catch (QueryException $e) {
            // Two copies of the same request in flight at once, and the
            // other one recorded the key between our lookup and our write.
            // The unique index is what actually enforces this; losing that
            // race is not an error worth showing anyone.
            //
            // Anything else is: the write has already been committed by
            // $next, so a key that failed to save leaves that write
            // unrecognisable and the next replay does it again — which is
            // the whole thing this class exists to stop. It must not be
            // swallowed the way a lost race can be.
            //
            // Reported rather than rethrown. Throwing here would answer a
            // sale that genuinely succeeded with a 500, and the phone
            // would queue it and send it again — turning a logging problem
            // into the duplicate it was trying to prevent.
            if (! $this->isDuplicateKey($e)) {
                report($e);
            }
        }

        return $response;
    }

    private static ?bool $hasTable = null;

    /**
     * Whether the table is here.
     *
     * A `true` is cached and a `false` is not, which is not a symmetry
     * mistake. A table does not vanish under a running process, so the
     * yes is worth keeping and saves an information_schema query on every
     * write. The no can stop being true at any moment — that is exactly
     * what running the migration does — and a cached one would leave a
     * worker that started during the deploy window with the protection
     * silently switched off until somebody restarted it. Nobody would
     * know to. The re-check costs a query per write, and only during the
     * minutes when the table really is missing.
     */
    private static function tableExists(): bool
    {
        if (self::$hasTable === true) {
            return true;
        }

        return self::$hasTable = Schema::hasTable('idempotent_requests');
    }

    /**
     * Drops the cached answer.
     *
     * For tests that take the table away mid-process. Nothing in the app
     * calls it: production only ever goes from absent to present, and
     * that direction needs no help because a `false` is never cached.
     */
    public static function forgetTableCache(): void
    {
        self::$hasTable = null;
    }

    /** Whether this is the unique index on idempotency_key, and not something worse. */
    private function isDuplicateKey(QueryException $e): bool
    {
        // 23000 is the SQL integrity-constraint class and 1062 is MySQL's
        // duplicate-entry inside it. Checked together rather than on the
        // driver code alone, which also covers a missing foreign key.
        return $e->getCode() === '23000'
            && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
