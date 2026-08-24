<?php

namespace App\Support;

use App\Exceptions\AlreadyClaimedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Doing something to a record that nobody else can be doing at the same
 * moment.
 *
 * Every "has this already happened?" guard in this app was written the
 * same way, and all of them had the same hole:
 *
 *     $chane = ChaneEntry::find($id);              // read
 *     if ($chane->status !== 'pending') { 409 }    // decide
 *     SaleRecorder::record($chane, ...);           // write
 *
 * Two sellers on two phones tapping the same batch a moment apart both
 * read `pending`, both pass the check, and both record. The batch is sold
 * twice — bread out of the warehouse twice, money against the seller
 * twice — and every guard in the code did exactly what it was written to
 * do. Twelve places were shaped like this and none of them held a lock.
 *
 * The idempotency key added the day before does not help here. That
 * catches one phone sending the same write twice; these are two different
 * people making two genuinely different requests, and they carry
 * different keys because they are different.
 *
 * So: re-read the row inside a transaction with `lockForUpdate`, and make
 * the decision against *that* copy. A second request arriving meanwhile
 * waits at the lock, then reads the state the first one left and is told
 * no. The re-read is the whole point — deciding on the copy fetched
 * before the transaction opened would leave the race exactly where it
 * was.
 */
final class Exclusively
{
    /**
     * Locks $model's row, re-reads it, and runs $guard against the fresh
     * copy before handing it to $work.
     *
     * $guard returns a message when the thing has already been claimed,
     * or null to go ahead. $work receives the locked, freshly-read model.
     *
     * @template T
     *
     * @param  callable(Model): ?string  $guard
     * @param  callable(Model): T  $work
     * @return T
     *
     * @throws AlreadyClaimedException
     */
    public static function claim(Model $model, callable $guard, callable $work)
    {
        return DB::transaction(function () use ($model, $guard, $work) {
            // Fresh, and held until this transaction ends. `find` on the
            // model's own query keeps any global scopes — a bakery's rows
            // stay a bakery's rows even here.
            $fresh = $model->newQuery()
                ->lockForUpdate()
                ->find($model->getKey());

            if ($fresh === null) {
                throw new AlreadyClaimedException('این مورد دیگر وجود ندارد.');
            }

            if ($message = $guard($fresh)) {
                throw new AlreadyClaimedException($message);
            }

            return $work($fresh);
        });
    }

    /**
     * The same, for a claim that is about a row not existing yet.
     *
     * «Has this employee been paid for this month» and «is there already
     * a batch for this dough» are the same race in a different shape:
     * two requests both find nothing, both insert, and the shop ends up
     * with two payslips for one month. There is no row to lock, so the
     * transaction is what serialises them — and the unique index behind
     * it is what actually enforces it, which is why $work should be
     * writing something the database will refuse to duplicate.
     *
     * @template T
     *
     * @param  callable(): ?string  $guard
     * @param  callable(): T  $work
     * @return T
     *
     * @throws AlreadyClaimedException
     */
    public static function once(callable $guard, callable $work)
    {
        return DB::transaction(function () use ($guard, $work) {
            if ($message = $guard()) {
                throw new AlreadyClaimedException($message);
            }

            return $work();
        });
    }
}
