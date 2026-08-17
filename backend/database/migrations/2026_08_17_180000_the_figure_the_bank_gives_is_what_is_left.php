<?php

use App\Models\Loan;
use App\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 3,076,688,000 Rial is what is still owed, not what was borrowed.
 *
 * It went in as the principal an hour ago on the reading that it was the
 * two agreements added up. The owner corrected that: «این مانده تاکنون
 * هست، پرداخت مال قبل بود» — the figure is today's outstanding, and the
 * 50,000,000 Rial instalment was paid before it was struck.
 *
 * The model counts what is left as principal less payments, so for the
 * remaining balance to read the bank's figure, the principal has to carry
 * the instalment that has already come off it:
 *
 *     3,076,688,000 + 50,000,000 = 3,126,688,000
 *
 * The payment is not removed to make the arithmetic work. That money did
 * leave حساب سفید on 8 Mordad and the account has to keep saying so —
 * deleting it to tidy a total is how a bank stops reconciling, and this
 * shop has already paid for that lesson once today.
 *
 * The count follows: 79 payments of 40,000,000 covers 3,160,000,000, the
 * first whole number of instalments that clears it. He says he may pay
 * three or four times the instalment to be rid of it sooner, which the
 * model handles on its own — instalments_paid is whole instalments out of
 * whatever has been paid, so overpaying moves the schedule forward rather
 * than confusing it.
 */
return new class extends Migration
{
    /** Stored in Toman. */
    private const WAS = 307_668_800;

    private const IS = 312_668_800;

    private const INSTALMENTS = 79;

    public function up(): void
    {
        DB::transaction(function () {
            $loan = Loan::withoutGlobalScopes()->first();

            if (! $loan || abs((float) $loan->principal - self::WAS) > 0.01) {
                return;
            }

            $loan->principal = self::IS;
            $loan->instalment_count = self::INSTALMENTS;
            $loan->note = trim(($loan->note ?? '').' مانده‌ی اعلامی بانک در ۲۶ مرداد '
                .Money::format(self::WAS * 10).' بود؛ اصل با احتساب قسط پرداخت‌شده‌ی پیش از آن'
                .' ثبت شده تا مانده همان عدد بانک بخواند.');
            $loan->save();
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $loan = Loan::withoutGlobalScopes()->first();

            if (! $loan || abs((float) $loan->principal - self::IS) > 0.01) {
                return;
            }

            $loan->principal = self::WAS;
            $loan->instalment_count = 77;
            $loan->save();
        });
    }
};
