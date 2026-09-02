<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Writes a batch's sale, however it was paid for.
 *
 * A batch is often settled in more than one way — part cash, part card —
 * so a sale arrives as a list of payment lines. Each becomes its own Sale
 * row, which keeps every report that groups by payment type working
 * unchanged; they are written together so the batch closes once and its
 * shortfall is counted once.
 *
 * Both the API and the admin panel come through here, so the two cannot
 * drift apart on how a shortfall or a money gap is worked out.
 *
 * @phpstan-type PaymentLine array{payment_type: string, bread_count: int, amount: float|null, customer_id: int|null, consumed_by_user_id: int|null, note: string|null}
 */
class SaleRecorder
{
    /**
     * @param  array<int, PaymentLine>  $lines
     * @return array<int, Sale>
     */
    public static function record(ChaneEntry $chane, array $lines, int $userId): array
    {
        $totalBread = array_sum(array_column($lines, 'bread_count'));

        return DB::transaction(function () use ($chane, $lines, $userId, $totalBread) {
            $breadPrice = (float) (CurrentBakery::get()->bread_price ?? 0);

            // Whatever the batch held beyond everything sold from it is a
            // temporary debt against the seller — computed from the batch's
            // own count, never from client input, so it can't be typed
            // away. Counted once for the batch rather than once per line.
            //
            // A batch is not always sold in one go. Measuring this sale
            // against the whole batch charged the seller for loaves that
            // simply had not sold yet, and then charged them again at the
            // next sale — a batch that sold out could end the day owing its
            // own size. What is already gone counts too.
            $soldBefore = (int) Sale::where('chane_entry_id', $chane->id)->sum('bread_count');

            $shortfallCount = max(0, $chane->chane_count - $soldBefore - $totalBread);
            $shortfallApplied = false;

            $created = [];

            foreach ($lines as $line) {
                $amount = $line['amount'] ?? null;

                // How far the money taken sits from what this bread should
                // have cost. Frozen here rather than recomputed, so a later
                // price change cannot rewrite what a seller already owed.
                // Bread given away is expected to bring in nothing, so it
                // is not a gap the seller has to answer for.
                $isGiveaway = in_array($line['payment_type'], Sale::GIVEAWAY_TYPES, true)
                    || in_array($line['payment_type'], Sale::SHORTFALL_TYPES, true);

                $difference = ($isGiveaway || $amount === null || $breadPrice <= 0)
                    ? null
                    : round((float) $amount - $line['bread_count'] * $breadPrice, 2);

                // Card money is settled to the account by the reader, so
                // the sale names the account it landed in. Cash and credit
                // name none: that money is still with the seller or the
                // customer, and posting it here would invent a deposit.
                $bankAccountId = in_array($line['payment_type'], Sale::BANKED_TYPES, true)
                    ? BankAccount::where('is_default', true)->value('id')
                    : null;

                // Bread the seller named as unaccounted-for is its own
                // shortfall. The automatic figure below is the backstop for
                // what they did not name; the two never cover the same
                // loaves, because a named line counts towards the batch.
                $isNamedShortfall = in_array($line['payment_type'], Sale::SHORTFALL_TYPES, true);
                $lineShortfall = $isNamedShortfall ? (int) $line['bread_count'] : 0;

                $carriesBatchShortfall = ! $isNamedShortfall
                    && ! $shortfallApplied
                    && $shortfallCount > 0;

                // Bread a worker took home is charged to that worker, not
                // to the seller who handed it over. The seller names them
                // at the moment it goes — «فروشنده انتخاب می‌کنه» — and the
                // value is frozen here for the same reason a shortfall's
                // is: a later price change must not rewrite what somebody
                // already owed. Only «منزل» carries a person; charity is a
                // gift and is owed by nobody.
                $consumedBy = $line['payment_type'] === Sale::HOME_TYPE
                    ? ($line['consumed_by_user_id'] ?? null)
                    : null;

                $created[] = Sale::create([
                    'chane_entry_id' => $chane->id,
                    'user_id' => $userId,
                    'consumed_by_user_id' => $consumedBy,
                    'consumed_amount' => $consumedBy !== null && $breadPrice > 0
                        ? round($line['bread_count'] * $breadPrice, 2)
                        : null,
                    'payment_type' => $line['payment_type'],
                    'bread_count' => $line['bread_count'],
                    // The batch's shortfall belongs to the batch, so it
                    // rides on the first line only and is never doubled.
                    'shortfall_count' => $lineShortfall > 0
                        ? $lineShortfall
                        : ($carriesBatchShortfall ? $shortfallCount : null),
                    'shortfall_amount' => $lineShortfall > 0
                        ? round($lineShortfall * $breadPrice, 2)
                        : ($carriesBatchShortfall
                            ? round($shortfallCount * $breadPrice, 2)
                            : null),
                    'amount_difference' => $difference,
                    'customer_id' => $line['customer_id'] ?? null,
                    'amount' => $amount,
                    'bank_account_id' => $bankAccountId,
                    'note' => $line['note'] ?? null,
                ]);

                // Only a line that actually carried the batch's shortfall
                // closes it off. A line the seller named as shortfall
                // carries its own, and must not consume the backstop.
                if ($carriesBatchShortfall) {
                    $shortfallApplied = true;
                }
            }

            // An earlier sale's shortfall was the right answer at the time
            // and is the wrong one now that more of the batch has gone.
            // Only figures nobody has acted on are revised: money that has
            // already changed hands stands, and the admin sees both.
            Sale::where('chane_entry_id', $chane->id)
                ->whereNotIn('id', array_column($created, 'id'))
                ->whereNotNull('shortfall_count')
                ->whereNull('shortfall_settled_on')
                ->whereNotIn('payment_type', Sale::SHORTFALL_TYPES)
                ->update(['shortfall_count' => null, 'shortfall_amount' => null]);

            $chane->update(['status' => 'sold']);

            return $created;
        });
    }

    /**
     * Works the batch's shortfall out again from what is on file now.
     *
     * The shortfall is a figure about the *batch* — chane shaped, less
     * bread accounted for — that rides on one of its sale rows. So it goes
     * stale the moment any row's bread count changes, and `bread_count`
     * stays editable after a sale is recorded.
     *
     * It went stale on batch #142 (1405/06/07): four lines written
     * together, then each corrected by hand a few minutes later. The
     * counts went up by 33 loaves and the shortfall stayed where it was,
     * so the seller was answering for 66 loaves when 33 were missing —
     * 3,300,000 rial of shortfall that was not there.
     *
     * This is the fourth thing on this model to go stale behind an edit,
     * after consignment stock, a worker's bread debt and a flour sale's
     * weight. The rule those three taught: a derived figure needs the edit
     * path as much as the create path.
     *
     * Rules kept identical to `record()`, which is the only other place
     * that decides this:
     *   - a line the seller *named* as shortfall carries its own count and
     *     is never overwritten here;
     *   - the automatic remainder rides on one unnamed line;
     *   - a shortfall already settled is money that changed hands, and
     *     stands whatever the arithmetic now says.
     */
    public static function refreshBatchShortfall(ChaneEntry $chane): void
    {
        $sales = Sale::where('chane_entry_id', $chane->id)->orderBy('id')->get();

        if ($sales->isEmpty()) {
            return;
        }

        $breadPrice = (float) (CurrentBakery::get()?->bread_price ?? 0);

        $remainder = max(0, (int) $chane->chane_count - (int) $sales->sum('bread_count'));

        $carried = false;

        foreach ($sales as $sale) {
            // Named shortfall: the seller said these loaves were missing,
            // and that is not a derived figure at all.
            if (in_array($sale->payment_type, Sale::SHORTFALL_TYPES, true)) {
                continue;
            }

            // Settled means somebody paid for it. Rewriting it would move
            // a debt that is already closed.
            if ($sale->shortfall_settled_on !== null) {
                $carried = true;

                continue;
            }

            $count = (! $carried && $remainder > 0) ? $remainder : null;

            $carried = $carried || $count !== null;

            $amount = $count === null ? null : round($count * $breadPrice, 2);

            if ((int) $sale->shortfall_count === (int) $count
                && (float) $sale->shortfall_amount === (float) $amount) {
                continue;
            }

            // Quietly: this is called *from* a model event, and a normal
            // save here would call it straight back.
            $sale->updateQuietly([
                'shortfall_count' => $count,
                'shortfall_amount' => $amount,
            ]);
        }
    }

    /**
     * Why a set of lines cannot be recorded, or null when they can.
     *
     * @param  array<int, PaymentLine>  $lines
     */
    public static function problemWith(ChaneEntry $chane, array $lines): ?string
    {
        if ($lines === []) {
            return 'برای حداقل یک نوع پرداخت تعداد نان وارد کنید.';
        }

        foreach ($lines as $line) {
            // Sales to schools or offices should name the buyer.
            if (in_array($line['payment_type'], Sale::DEBT_TYPES, true)
                && empty($line['customer_id'])) {
                return 'برای این نوع پرداخت، انتخاب مشتری الزامی است.';
            }
        }

        $totalBread = array_sum(array_column($lines, 'bread_count'));

        // Counted against everything already sold from the batch, not just
        // against this sale: checking each transaction on its own let a
        // batch of a hundred be sold sixty at a time, twice over.
        $soldBefore = (int) Sale::where('chane_entry_id', $chane->id)->sum('bread_count');

        if ($soldBefore + $totalBread > $chane->chane_count) {
            $remaining = max(0, $chane->chane_count - $soldBefore);

            return 'مجموع تعداد نان ('.number_format($totalBread).') از باقی‌مانده این دسته ('
                .number_format($remaining).' از '.number_format($chane->chane_count)
                .' چانه) بیشتر است.';
        }

        return null;
    }
}
