<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_starts', function (Blueprint $table) {
            // Which late day of the month this was: the 1st, 2nd, 3rd late
            // day carries a warning only, the 4th onwards costs money.
            $table->unsignedInteger('late_sequence')->default(0)->after('late_minutes');

            // Frozen at tick time, in Toman, like every other amount. The
            // tariff may be changed later and must not rewrite past penalties.
            $table->decimal('penalty_amount', 14, 2)->default(0)->after('late_sequence');
        });

        Schema::table('bakeries', function (Blueprint $table) {
            // Late days that cost nothing but a warning.
            $table->unsignedInteger('late_free_days')->default(3)
                ->after('baking_start_deadline');

            // The last late day charged at the lower rate.
            $table->unsignedInteger('late_tier1_last_day')->default(7)
                ->after('late_free_days');

            // Stored in Toman: 200,000 Toman is 2,000,000 Rial.
            $table->decimal('late_tier1_amount', 14, 2)->default(200000)
                ->after('late_tier1_last_day');

            // 500,000 Toman is 5,000,000 Rial.
            $table->decimal('late_tier2_amount', 14, 2)->default(500000)
                ->after('late_tier1_amount');
        });
    }

    public function down(): void
    {
        Schema::table('work_starts', function (Blueprint $table) {
            $table->dropColumn(['late_sequence', 'penalty_amount']);
        });

        Schema::table('bakeries', function (Blueprint $table) {
            $table->dropColumn([
                'late_free_days',
                'late_tier1_last_day',
                'late_tier1_amount',
                'late_tier2_amount',
            ]);
        });
    }
};
