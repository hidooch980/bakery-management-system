<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The flour purchase price was stored as 12,000 a kilo when the shop pays
 * 1,200.
 *
 * The shop displays in Rial and this setting was filled straight from the
 * form, so a price typed as 12,000 Rial was stored as 12,000 Toman — the
 * same ten-fold error already fixed in expenses, in salaries, and in the
 * settings endpoint itself. The endpoint converts properly now; this
 * corrects the figure it left behind, and the dated price beside it, so
 * past months recompute against what was really paid.
 *
 * Confirmed against the shop's own invoice: "ارد ۱۰۰", one hundred 40 kg
 * sacks for 4,800,000 Toman — 48,000 a sack, 1,200 a kilo.
 *
 * Costing every loaf at ten times the price of its flour made the bread
 * look barely worth baking: flour alone read as 68.6% of what the bread
 * sold for, before a single wage or litre of fuel.
 *
 * Written as a migration rather than an update run by hand so the change
 * is reviewable, repeatable, and lands the same way on every copy of the
 * database. It matches on the wrong value, so running it twice is safe
 * and running it against an already-correct shop does nothing.
 */
return new class extends Migration
{
    private const WRONG = 12000;

    private const RIGHT = 1200;

    public function up(): void
    {
        DB::table('bakeries')
            ->where('flour_purchase_price_per_kg', self::WRONG)
            ->update(['flour_purchase_price_per_kg' => self::RIGHT]);

        // The dated prices are seeded from the setting, so the one on
        // record carries the same error.
        DB::table('flour_prices')
            ->where('price_per_kg', self::WRONG)
            ->update(['price_per_kg' => self::RIGHT]);
    }

    public function down(): void
    {
        DB::table('bakeries')
            ->where('flour_purchase_price_per_kg', self::RIGHT)
            ->update(['flour_purchase_price_per_kg' => self::WRONG]);

        DB::table('flour_prices')
            ->where('price_per_kg', self::RIGHT)
            ->update(['price_per_kg' => self::WRONG]);
    }
};
