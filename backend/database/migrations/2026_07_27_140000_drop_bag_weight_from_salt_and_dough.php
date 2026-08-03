<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Salt and dough are handled by weight, not by the sack.
     *
     * They were given a nominal sack size so the balance could be shown as
     * a bag count, but the shop does not measure them that way — dough
     * least of all, which is never in a sack at all. Clearing the size
     * makes both read in kilograms only. Flour keeps its own, which comes
     * from the production formula rather than this column.
     */
    public function up(): void
    {
        DB::table('inventory_items')->whereIn('key', ['salt', 'dough'])
            ->update(['bag_weight_kg' => null]);
    }

    public function down(): void
    {
        DB::table('inventory_items')->where('key', 'salt')->update(['bag_weight_kg' => 25]);
        DB::table('inventory_items')->where('key', 'dough')->update(['bag_weight_kg' => 10]);
    }
};
