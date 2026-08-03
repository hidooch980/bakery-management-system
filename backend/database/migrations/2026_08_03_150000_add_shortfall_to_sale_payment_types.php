<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bread the seller cannot account for becomes a line of its own.
 *
 * It was already counted — whatever a batch held beyond everything sold
 * from it was charged to the seller automatically. But an amount nobody
 * typed is an amount nobody checked, and the first the seller heard of it
 * was a figure on their account at the end of the month.
 *
 * Recorded as a line, it is named at the counter while the batch is still
 * in front of them. The automatic count stays as the backstop: it only has
 * anything left to charge when the lines do not add up to the batch.
 */
return new class extends Migration
{
    private const WITH = "'cash','card','credit','home','schools','charity','shortfall','other'";

    private const WITHOUT = "'cash','card','credit','home','schools','charity','other'";

    public function up(): void
    {
        DB::statement('ALTER TABLE sales MODIFY payment_type ENUM('.self::WITH.') NOT NULL');
    }

    public function down(): void
    {
        DB::table('sales')->where('payment_type', 'shortfall')
            ->update(['payment_type' => 'other']);

        DB::statement('ALTER TABLE sales MODIFY payment_type ENUM('.self::WITHOUT.') NOT NULL');
    }
};
