<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «کیسه بیاد، فروش کیلویی فقط داریم» — the owner, 1405/06/10.
 *
 * The store is read in sacks; kilograms belong to selling, where flour
 * really is sold by the kilo. The machinery for this was already built and
 * is data-driven: an item shows a sack count when its sack size is known.
 * What was missing is the size itself.
 *
 * An earlier migration cleared salt's on the belief that it is weighed
 * rather than counted. It is weighed *into the dough* — it arrives in
 * sacks like everything else, and the owner had already said what one
 * weighs: «هر کیسه نمک ۲۵» (2026-08-17). That sentence came after the
 * clearing, so this restores it rather than reversing a decision.
 *
 * Dry yeast is deliberately left alone. Nobody has said what a sack of it
 * weighs, and a bag count converted at an invented figure is worse than a
 * weight — so it keeps reading in kilograms until he says otherwise.
 *
 * Matches on the empty value, so it is idempotent and will not overwrite
 * a size somebody has since typed into the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('inventory_items')
            ->where('key', 'salt')
            ->whereNull('bag_weight_kg')
            ->update(['bag_weight_kg' => 25]);
    }

    public function down(): void
    {
        DB::table('inventory_items')
            ->where('key', 'salt')
            ->where('bag_weight_kg', 25)
            ->update(['bag_weight_kg' => null]);
    }
};
