<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many litres of diesel each sack of flour entitles the shop to.
 *
 * The quota is not a figure the bakery negotiates each month — it follows
 * the flour allocation at a fixed rate. Typing litres by hand meant the two
 * could drift apart, and the one that is wrong is always the one nobody
 * checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->decimal('diesel_litres_per_bag', 8, 2)
                ->default(5)
                ->after('flour_transport_by_factory');
        });
    }

    public function down(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->dropColumn('diesel_litres_per_bag');
        });
    }
};
