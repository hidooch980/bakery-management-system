<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bread that was destroyed becomes a line of its own.
 *
 * A batch that comes out burnt, or goes on the floor, or sits until it is
 * no longer bread, had nowhere honest to go. The seller closing the batch
 * had two choices and both were wrong:
 *
 *   - **کسری** — which lands on their account. They pay for the oven's
 *     mistake out of their own wages.
 *   - **خیرات** — which is a lie, and one that spoils a real figure: the
 *     shop cannot then tell what it actually gave away.
 *
 * So «ضایعات»: owed by nobody, like charity, but named as a loss rather
 * than a gift, because to the owner those are opposite facts. Charity is
 * a decision he made. Waste is a problem he has.
 *
 * A category that costs nobody is exactly the one somebody would use to
 * cover a theft, so it is never silent: it is reported beside the other
 * payment types, and the issue scanner raises it when it grows past the
 * ordinary. The answer to «this could be abused» is that it is visible,
 * not that the seller keeps paying for burnt bread.
 */
return new class extends Migration
{
    private const WITH = "'cash','card','credit','home','schools','charity','shortfall','waste','other'";

    private const WITHOUT = "'cash','card','credit','home','schools','charity','shortfall','other'";

    public function up(): void
    {
        DB::statement('ALTER TABLE sales MODIFY payment_type ENUM('.self::WITH.') NOT NULL');
    }

    public function down(): void
    {
        // «other» rather than «shortfall» on the way back: rolling this
        // migration back must not hand somebody a debt they never had.
        DB::table('sales')->where('payment_type', 'waste')
            ->update(['payment_type' => 'other']);

        DB::statement('ALTER TABLE sales MODIFY payment_type ENUM('.self::WITHOUT.') NOT NULL');
    }
};
