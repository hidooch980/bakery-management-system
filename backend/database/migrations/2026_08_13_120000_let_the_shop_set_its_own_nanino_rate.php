<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many nanino loaves a sack counts for.
 *
 * Sixty-four is what the sāmāne works to, and it was written into the code
 * as a constant. It is a rule the authority sets, not a fact about baking —
 * when it changes, the shop should not need a new build to follow it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->unsignedSmallInteger('nanino_per_bag')
                ->default(64)
                ->after('nanino_chane_weight_kg');
        });
    }

    public function down(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->dropColumn('nanino_per_bag');
        });
    }
};
