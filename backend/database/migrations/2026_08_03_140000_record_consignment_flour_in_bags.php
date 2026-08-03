<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flour lent to a neighbour is counted in sacks, like every other flour.
 *
 * Nobody carries 200 kilos next door — they carry five sacks. The weight
 * follows from the sack size in settings, the same way the monthly quota
 * and a delivery already work, so the three cannot disagree about what a
 * sack weighs.
 *
 * The weight stays the stored truth; the sack count rides alongside it so
 * the record still reads back the way it was entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignment_flours', function (Blueprint $table) {
            $table->decimal('bags', 10, 2)->nullable()->after('direction');
        });

        // Existing rows were entered by weight. Their sack count is derived
        // once, from today's sack size, so the column is never blank for a
        // record that plainly had sacks behind it.
        $bagWeight = (float) DB::table('bakeries')->value('flour_bag_weight_kg') ?: 40.0;

        DB::table('consignment_flours')->update([
            'bags' => DB::raw("ROUND(amount_kg / {$bagWeight}, 2)"),
        ]);
    }

    public function down(): void
    {
        Schema::table('consignment_flours', function (Blueprint $table) {
            $table->dropColumn('bags');
        });
    }
};
