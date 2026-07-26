<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash a seller takes stays in their pocket until it is handed over, and
     * any gap between the money recorded and what the bread should have cost
     * is theirs to answer for. Both sit on the seller's temporary account
     * until it is settled.
     *
     * The difference is frozen at the point of sale rather than recomputed,
     * so a later change to the bread price cannot rewrite what a seller
     * already owed — the same rule the late-start penalties follow.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('amount_difference', 14, 2)->nullable()->after('shortfall_settled_on');
            $table->date('cash_settled_on')->nullable()->after('amount_difference');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['amount_difference', 'cash_settled_on']);
        });
    }
};
