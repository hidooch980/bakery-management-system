<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two wage payments were entered a tenth of their real size.
 *
 * Each was handed over as 20,000,000 Rial and typed as 2,000,000 — a
 * missing zero, not a unit mistake: the amounts stored, 200,000 Toman,
 * are exactly a tenth of the 2,000,000 Toman that actually left the till.
 *
 * The shop pays wages this way rather than through the payroll screen, so
 * these two rows are the whole of its recorded payroll; understating them
 * overstated the cash profit the partners are paid on by 3,600,000 Toman.
 *
 * The second row was recorded without a name. It was Mohammad Hanif's, so
 * it says so now — a payslip that cannot say who it paid is not a record
 * of anything.
 *
 * Matched on the wrong amount, so it corrects each row once and does
 * nothing on a second run.
 */
return new class extends Migration
{
    private const WRONG = 200000;

    private const RIGHT = 2000000;

    public function up(): void
    {
        DB::table('expenses')
            ->where('category', 'salary')
            ->where('amount', self::WRONG)
            ->where('title', 'علی الحساب عبدالله')
            ->update(['amount' => self::RIGHT]);

        DB::table('expenses')
            ->where('category', 'salary')
            ->where('amount', self::WRONG)
            ->where('title', 'علی الحساب')
            ->update([
                'amount' => self::RIGHT,
                'title' => 'علی الحساب محمد حنیف',
            ]);
    }

    public function down(): void
    {
        DB::table('expenses')
            ->where('category', 'salary')
            ->where('amount', self::RIGHT)
            ->where('title', 'علی الحساب عبدالله')
            ->update(['amount' => self::WRONG]);

        DB::table('expenses')
            ->where('category', 'salary')
            ->where('amount', self::RIGHT)
            ->where('title', 'علی الحساب محمد حنیف')
            ->update([
                'amount' => self::WRONG,
                'title' => 'علی الحساب',
            ]);
    }
};
