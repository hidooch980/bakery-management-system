<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two halves of the balance sheet the ledger cannot work out for itself.
 *
 * Everything else on a balance sheet is already recorded as it happens: the
 * bank knows its balance, the store knows its flour, a credit sale knows it
 * is owed. An oven and a loan are different — nothing in the day's work
 * mentions them, so they have to be written down once and kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->index();
            $table->string('title');
            $table->string('category')->default('equipment');

            // What it cost, and what it is reckoned to be worth now. Kept
            // apart because a five-year-old oven is not worth what it cost,
            // and pretending otherwise inflates the shop's own accounts.
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->decimal('current_value', 15, 2)->nullable();
            $table->date('purchased_on')->nullable();

            // Sold or scrapped rather than deleted: it was on the balance
            // sheet last month and that has to stay true of last month.
            $table->date('disposed_on')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->index();
            $table->string('title');
            $table->string('lender')->nullable();

            $table->decimal('principal', 15, 2)->default(0);
            $table->decimal('instalment_amount', 15, 2)->default(0);
            $table->unsignedSmallInteger('instalment_count')->default(0);
            $table->date('first_due_on')->nullable();

            $table->date('settled_on')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // Each repayment, so what is left is counted rather than typed — a
        // remaining balance kept by hand drifts the first time someone pays
        // twice in a month or misses one.
        Schema::create('loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->index();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('bank_account_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->date('paid_on');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_payments');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('fixed_assets');
    }
};
