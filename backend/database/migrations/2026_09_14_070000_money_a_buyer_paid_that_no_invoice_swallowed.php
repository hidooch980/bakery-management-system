<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money a buyer handed over that did not land on an invoice boundary.
 *
 * A sale settles whole — `settled_on` is a date, not a part share — so a
 * school paying ۲۵۰ against three invoices of ۱۰۰ closes two of them and
 * leaves ۵۰ with nowhere to go. Until now that ۵۰ was simply dropped: the
 * seller was told the collection succeeded, no account received it, and
 * the buyer's debt did not move by it. The money was in the seller's hand
 * and nothing in the shop knew it existed.
 *
 * This is the buyer's side of `seller_account_credits`, deliberately the
 * same shape: the remainder waits here and is spent on the next collection
 * before any new money is asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->nullable()->index();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Who took the money, so a credit can be argued about later.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Positive when the shop holds money for the buyer, negative
            // when a later collection spends it.
            $table->decimal('amount', 14, 2);

            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credits');
    }
};
