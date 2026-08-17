<?php

use App\Models\Loan;
use App\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The machine was bought with two loans, and only one principal was written
 * down.
 *
 * Bank Saderat wrote two agreements of 1,538,344,000 Rial each. The shop
 * repays them in a single transfer on the 10th, so on 2026-08-16 I recorded
 * one row rather than two — splitting every future payment by hand would
 * have been a worse trap than this one. That reasoning was right about the
 * instalment and wrong about the principal: the row took the *combined*
 * 40,000,000 Rial monthly and a *single* loan's 1,538,344,000 owing.
 *
 * The owner gave the total on 2026-08-17: «کل وام ۳۰۷۶۶۸۸۰۰۰ هست». Exactly
 * twice what is on file, which is the arithmetic saying the same thing.
 *
 * So the shop's books have been a **1,538,344,000 Rial liability short**
 * since the loan was entered — the largest single figure missing from the
 * balance sheet, and larger than the bank balance it sits beside.
 *
 * The instalment is not touched. He pays 50,000,000 a month against a
 * 40,000,000 obligation and said so plainly — «من وام بیشتر پرداخت می‌کنم»
 * — so 40,000,000 is the agreement and the extra is his own repayment
 * ahead of schedule, which the model already handles: instalments_paid
 * counts whole instalments out of what has been paid.
 *
 * The count follows the principal rather than being restated: 77 payments
 * of 40,000,000 covers 3,080,000,000, which is the first whole number of
 * instalments that clears the debt.
 */
return new class extends Migration
{
    /** Stored in Toman. */
    private const WAS = 153_834_400;

    private const IS = 307_668_800;

    private const INSTALMENTS = 77;

    public function up(): void
    {
        DB::transaction(function () {
            $loan = Loan::withoutGlobalScopes()->first();

            // Every one of these has to match, or this is not the row this
            // was written about — a fresh database, or any install but this
            // shop's.
            if (! $loan
                || abs((float) $loan->principal - self::WAS) > 0.01
                || abs((float) $loan->instalment_amount - 4_000_000) > 0.01) {
                return;
            }

            $loan->principal = self::IS;
            $loan->instalment_count = self::INSTALMENTS;
            $loan->note = trim(($loan->note ?? '').' اصلاح ۲۶ مرداد: این یک ردیف برای دو وام'
                .' بانک صادرات است و اصل هر دو روی هم '.Money::format(self::IS)
                .' می‌شود؛ پیش‌تر فقط یکی ثبت شده بود.');
            $loan->save();
        });
    }

    /**
     * Reversible. One figure, one source, and if the bank's own statement
     * says otherwise the honest answer is to put it back rather than to
     * cover it with a second correction.
     */
    public function down(): void
    {
        DB::transaction(function () {
            $loan = Loan::withoutGlobalScopes()->first();

            if (! $loan || abs((float) $loan->principal - self::IS) > 0.01) {
                return;
            }

            $loan->principal = self::WAS;
            $loan->instalment_count = 39;
            $loan->save();
        });
    }
};
