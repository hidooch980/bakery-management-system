<?php

namespace App\Http\Middleware;

use App\Models\IdempotentRequest;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
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

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        // No key, no protection — and that is the honest default. Older
        // app versions send nothing, and refusing them would take the
        // shop offline the moment this deploys.
        if (blank($key) || ! $request->user()) {
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
            if ($seen->user_id !== $request->user()->id
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
        } catch (QueryException) {
            // Two copies of the same request in flight at once, and the
            // other one recorded the key between our lookup and our write.
            // The unique index is what actually enforces this; losing the
            // race is not an error worth showing anyone.
        }

        return $response;
    }
}
