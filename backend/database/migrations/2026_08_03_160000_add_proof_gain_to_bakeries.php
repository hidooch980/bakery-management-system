<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dough yields more after it has rested than the mixing bowl suggests.
 *
 * The formula adds up what goes in — flour, water, salt, yeast — and that
 * is what the batch weighs when it is mixed. But it is shaped after it has
 * proved, and by then it gives noticeably more chane than that raw weight
 * divided by the weight of one. In this shop it is worth up to ninety
 * chane on a batch.
 *
 * Naming it as a setting is better than hiding it in a tolerance: it is a
 * real property of the dough, the shop can measure its own, and the
 * overshoot guard goes back to catching what it was meant to catch — a
 * count typed one digit too long.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->decimal('proof_gain_ratio', 5, 4)
                ->default(0.115)
                ->after('dough_loss_ratio');
        });
    }

    public function down(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->dropColumn('proof_gain_ratio');
        });
    }
};
