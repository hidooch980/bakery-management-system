<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a seller hand over whatever they have, rather than a sum that
 * happens to close whole sales.
 *
 * A sale settles all at once — cash_settled_on is a date, not a part
 * share — so a payment that does not line up with sale boundaries has
 * nowhere to go. Rather than teach every report to understand a half
 * settled sale, the leftover is held here as credit on the seller's
 * account and spent on the next settlement before any new money is asked
 * for.
 *
 * So: pay 500,000 against 800,000 of debt made of a 300,000 and a
 * 600,000 sale, and the 300,000 closes, 200,000 sits here, and the
 * seller owes 400,000 — which is what they would say themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_account_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->nullable()->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Positive when the shop holds money for the seller, negative
            // when a later settlement spends it.
            $table->decimal('amount', 14, 2);

            $table->foreignId('settlement_request_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_account_credits');
    }
};
