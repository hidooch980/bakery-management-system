<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The month's diesel quota, and what has actually been drawn against it.
 *
 * Fuel was only ever an expense category — money out, with no record of how
 * many litres the shop was entitled to or how much of that was left. An oven
 * that runs out mid-bake is not a bookkeeping problem, so the quota is worth
 * tracking the same way flour is.
 *
 * Deliveries rather than consumption: what a tanker actually dropped is a
 * number the shop can read off a docket, where litres burned per hour is a
 * guess dressed up as a measurement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diesel_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->nullable()->index();

            $table->date('month_start');
            $table->string('month_label');
            $table->decimal('total_litres', 12, 2);

            // Litres unused last month that the depot let the shop carry.
            $table->decimal('carryover_litres', 12, 2)->default(0);
            $table->string('note')->nullable();
            $table->timestamps();

            // One quota per shop per month; a second row would mean two
            // answers to how much is left.
            $table->unique(['bakery_id', 'month_start']);
        });

        Schema::create('diesel_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->date('received_on');
            $table->decimal('litres', 12, 2);

            // What it cost, when the shop paid rather than drew on quota.
            // Nullable: a subsidised delivery has no invoice.
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('docket_number')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['bakery_id', 'received_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diesel_deliveries');
        Schema::dropIfExists('diesel_allocations');
    }
};
