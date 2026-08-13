<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The litres a sack earns are not fixed — the depot allows more some
 * months and less others.
 *
 * The rate lived only in settings, one value for all time. The litres it
 * produced were already frozen into each allocation, so past months could
 * not be restated by changing it; but nothing recorded which rate a month
 * had been given, so a quota of 2,230 litres could not say whether it came
 * from 6.5 a sack or from 7 and a smaller quota. The month that most needs
 * explaining — the one somebody queries — is always the one already past.
 *
 * The setting stays, as the default a new month starts from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diesel_allocations', function (Blueprint $table) {
            $table->decimal('litres_per_bag', 8, 2)->nullable()->after('total_litres');
        });

        // What every existing month was in fact given, since there has only
        // ever been one rate in force.
        $rate = DB::table('bakeries')->value('diesel_litres_per_bag') ?? 5;

        DB::table('diesel_allocations')
            ->whereNull('litres_per_bag')
            ->update(['litres_per_bag' => $rate]);
    }

    public function down(): void
    {
        Schema::table('diesel_allocations', function (Blueprint $table) {
            $table->dropColumn('litres_per_bag');
        });
    }
};
