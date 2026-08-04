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
 * @phpstan-type PaymentLine array{payment_type: string, bread_count: int, amount: float|null, customer_id: int|null, note: string|null}
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
            $shortfallCount = max(0, $chane->chane_count - $totalBread);
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

                $created[] = Sale::create([
                    'chane_entry_id' => $chane->id,
                    'user_id' => $userId,
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

            $chane->update(['status' => 'sold']);

            return $created;
        });
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

        if ($totalBread > $chane->chane_count) {
            return 'مجموع تعداد نان ('.number_format($totalBread).') از تعداد چانه این دسته ('
                .number_format($chane->chane_count).') بیشتر است.';
        }

        return null;
    }
}
