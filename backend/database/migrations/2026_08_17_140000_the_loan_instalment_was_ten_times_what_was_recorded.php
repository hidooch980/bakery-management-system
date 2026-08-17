<?php

use App\Models\LoanPayment;
use App\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The instalment paid on 8 Mordad was 50,000,000 Rial, not 5,000,000.
 *
 * The owner said so plainly on 2026-08-17, twice, after being shown what
 * it would cost: «۵۰ میلیون ریال». This is the third ten-times error this
 * system has carried — the wage amounts, then two advance postings of
 * 20,000,000 Rial written as 2,000,000, now this.
 *
 * **It moves the bank.** حساب سفید goes from 594,073,258 Rial to
 * 549,073,258, because 45,000,000 more left the account than the ledger
 * knew about. That figure was reconciled against the statement on
 * 2026-08-16 and matched, so one of the two is wrong and the owner has
 * been told which way this pushes it. He asked for the correction anyway,
 * having been shown the number; the statement is his to check.
 *
 * Saved through the model rather than written into the row, because
 * PostsToBankAccount rebuilds the bank posting on save and nowhere else. An
 * amount corrected straight in the database leaves the withdrawal at the
 * old figure — which is exactly how two of the earlier ten-times errors
 * survived their first correction.
 *
 * One consequence worth naming: at 50,000,000 the first 40,000,000
 * instalment is covered, so the loan's schedule steps forward a month and
 * the «قسط عقب افتاده» warning goes quiet on its own. That is a symptom of
 * the fix, not the reason for it.
 */
return new class extends Migration
{
    /** Stored in Toman: 5,000,000 Toman is 50,000,000 Rial. */
    private const WAS = 500_000;

    private const IS = 5_000_000;

    private const PAID_ON = '2026-07-30';

    public function up(): void
    {
        DB::transaction(function () {
            $payment = LoanPayment::withoutGlobalScopes()
                ->whereDate('paid_on', self::PAID_ON)
                ->first();

            // Every one of these has to match or this is not the row this
            // was written about — a fresh database, or any install but this
            // shop's.
            if (! $payment || abs((float) $payment->amount - self::WAS) > 0.01) {
                return;
            }

            $payment->amount = self::IS;
            $payment->note = trim(($payment->note ?? '').' اصلاح ۲۶ مرداد: مبلغ '
                .Money::format(self::IS).' بود و ده برابر کوچک‌تر ثبت شده بود.');
            $payment->save();
        });
    }

    /**
     * Reversible, unusually — because this one is a single figure with a
     * single source, and if the bank statement turns out to say 594,073,258
     * after all then the honest thing is to put it back rather than to
     * paper over it with a second correction.
     */
    public function down(): void
    {
        DB::transaction(function () {
            $payment = LoanPayment::withoutGlobalScopes()
                ->whereDate('paid_on', self::PAID_ON)
                ->first();

            if (! $payment || abs((float) $payment->amount - self::IS) > 0.01) {
                return;
            }

            $payment->amount = self::WAS;
            $payment->save();
        });
    }
};
