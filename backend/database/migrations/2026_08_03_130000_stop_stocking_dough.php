<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dough stops being a stocked good.
 *
 * The warehouse holds what the shop buys: flour, salt and yeast. Dough is
 * mixed and shaped the same day and never sits on a shelf, so carrying it
 * as stock only gave every gram of difference between the formula and the
 * scale somewhere to settle. It had silted up to 640kg of dough that no
 * one could point at — a figure the shop was being asked to believe in.
 *
 * The batch record still says what was mixed, and the overshoot guard
 * still refuses a count bigger than the batch can hold; both read the
 * formula, which is where that answer always came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('inventory_items')->where('key', 'dough')->value('id');

        if ($id === null) {
            return;
        }

        // The movements go with it. They only ever balanced dough against
        // itself, so nothing else in the books reads them.
        DB::table('inventory_movements')->where('inventory_item_id', $id)->delete();
        DB::table('inventory_items')->where('id', $id)->delete();
    }

    public function down(): void
    {
        DB::table('inventory_items')->updateOrInsert(
            ['key' => 'dough'],
            [
                'name' => 'خمیر',
                'unit' => 'کیلوگرم',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
};
