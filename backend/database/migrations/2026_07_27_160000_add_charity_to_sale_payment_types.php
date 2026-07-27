<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bread given away — to a mosque, a religious school or anyone in need —
 * is a payment type of its own. It moves real bread but brings in no
 * money, so recording it as a sale at zero would read as a shortfall the
 * seller has to answer for.
 */
return new class extends Migration
{
    private const WITH_CHARITY = "'cash','card','credit','home','schools','charity','other'";

    private const WITHOUT_CHARITY = "'cash','card','credit','home','schools','other'";

    public function up(): void
    {
        DB::statement(
            'ALTER TABLE sales MODIFY payment_type ENUM('.self::WITH_CHARITY.') NOT NULL'
        );
    }

    public function down(): void
    {
        // Anything already given away has nowhere to go in the old set, so
        // it is filed under "other" rather than losing the row.
        DB::table('sales')->where('payment_type', 'charity')
            ->update(['payment_type' => 'other']);

        DB::statement(
            'ALTER TABLE sales MODIFY payment_type ENUM('.self::WITHOUT_CHARITY.') NOT NULL'
        );
    }
};
