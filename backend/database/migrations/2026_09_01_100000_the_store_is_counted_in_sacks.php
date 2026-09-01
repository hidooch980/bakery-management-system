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
 * clearing, so this restores it rather than reversing a decision. He gave
 * dry yeast's size when asked: «خمیر خشک کیسه ۱۰ کیلو» (1405/06/10).
 *
 * Sizes are asked for rather than assumed. A good nobody has sized still
 * reads in kilograms and still refuses a sack count, because a bag count
 * converted at an invented figure is worse than a plain weight — it is
 * only that every good this shop stocks has now been sized.
 *
 * Matches on the empty value, so it is idempotent and will not overwrite
 * a size somebody has since typed into the panel.
 */
return new class extends Migration
{
    /** What the owner has said each sack weighs. */
    private const SIZES = [
        // «هر کیسه نمک ۲۵» — 2026-08-17.
        'salt' => 25,
        // «خمیر خشک کیسه ۱۰ کیلو» — 1405/06/10.
        'yeast_dry' => 10,
    ];

    public function up(): void
    {
        foreach (self::SIZES as $key => $kg) {
            DB::table('inventory_items')
                ->where('key', $key)
                ->whereNull('bag_weight_kg')
                ->update(['bag_weight_kg' => $kg]);
        }
    }

    public function down(): void
    {
        foreach (self::SIZES as $key => $kg) {
            DB::table('inventory_items')
                ->where('key', $key)
                ->where('bag_weight_kg', $kg)
                ->update(['bag_weight_kg' => null]);
        }
    }
};
