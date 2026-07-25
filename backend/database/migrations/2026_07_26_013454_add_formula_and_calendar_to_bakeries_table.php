<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The dough formula lets the system derive chane weight from the number of
     * flour bags, so the chane gir no longer types weights by hand.
     *
     * One bag of flour, plus water and salt at the configured ratios, yields a
     * known dough weight; dividing by the per-chane weight gives the count.
     */
    public function up(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            // Weight of a single flour bag, e.g. 40 kg.
            $table->decimal('flour_bag_weight_kg', 8, 3)->default(40)->after('description');

            // Water and salt added per kilogram of flour.
            $table->decimal('water_ratio', 5, 3)->default(0.6)->after('flour_bag_weight_kg');
            $table->decimal('salt_ratio', 5, 4)->default(0.015)->after('water_ratio');

            // Share of dough lost to evaporation and handling, as a fraction.
            $table->decimal('dough_loss_ratio', 5, 4)->default(0)->after('salt_ratio');

            // Which calendar the panel and app display dates in.
            $table->enum('calendar', ['jalali', 'hijri', 'gregorian'])
                ->default('jalali')
                ->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->dropColumn([
                'flour_bag_weight_kg',
                'water_ratio',
                'salt_ratio',
                'dough_loss_ratio',
                'calendar',
            ]);
        });
    }
};
