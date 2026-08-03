<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Yeast joins the recipe, in the two forms the shop keeps.
 *
 * A sack of flour takes salt, water and a couple of hundred grams of yeast.
 * The yeast comes either dry or fresh, and which one is used is a seasonal
 * choice — fresh proves faster, so it is what winter calls for. Both are
 * bought and stocked, so both are counted, and a batch records which it
 * took rather than the warehouse guessing.
 *
 * The salt and water ratios are corrected here too: the shop works to 700g
 * of salt and 20 litres of water a sack, not the 600g and 28 litres the
 * settings had drifted to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            // Yeast per kilo of flour. 200g to a 40kg sack is 0.005.
            $table->decimal('yeast_ratio', 6, 5)
                ->default(0.005)
                ->after('salt_ratio');
        });

        Schema::table('dough_entries', function (Blueprint $table) {
            $table->enum('yeast_type', ['dry', 'wet'])
                ->default('dry')
                ->after('bag_count');
        });

        // 700g salt and 20 litres of water to a 40kg sack.
        DB::table('bakeries')->update([
            'salt_ratio' => 0.0175,
            'water_ratio' => 0.5,
        ]);

        foreach ([
            'yeast_dry' => 'خمیرمایه خشک',
            'yeast_wet' => 'خمیرمایه تر',
        ] as $key => $name) {
            DB::table('inventory_items')->updateOrInsert(
                ['key' => $key],
                ['name' => $name, 'unit' => 'کیلوگرم', 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('inventory_items')->whereIn('key', ['yeast_dry', 'yeast_wet'])->delete();

        DB::table('bakeries')->update([
            'salt_ratio' => 0.015,
            'water_ratio' => 0.6,
        ]);

        Schema::table('dough_entries', function (Blueprint $table) {
            $table->dropColumn('yeast_type');
        });

        Schema::table('bakeries', function (Blueprint $table) {
            $table->dropColumn('yeast_ratio');
        });
    }
};
