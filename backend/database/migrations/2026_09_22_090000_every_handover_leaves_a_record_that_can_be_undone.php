<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row for every time a seller handed money over.
 *
 * There was none. A seller's own request left a `settlement_requests` row,
 * but the owner settling somebody directly — which is what actually
 * happens, at the counter, on the phone — left nothing but a bank
 * movement with a note on it. So two of the owner's questions had no
 * answer at all:
 *
 *   «سابقهٔ تسویه‌های فروشنده» — there was no list to show. The sales
 *   went quiet and the money appeared in the till, and nothing anywhere
 *   joined those two facts together.
 *
 *   «اصلاح تسویهٔ اشتباه» — a settlement could not be undone because
 *   nothing recorded what it had done. Which sales closed, how much was
 *   cash and how much was card, which account it reached: all of it was
 *   knowable only by reading the sales table and guessing.
 *
 * So the handover itself becomes a thing, with what it closed written on
 * it. `sale_ids` and `credit_ids` are what makes a reversal exact rather
 * than a re-derivation: reopening «the sales that look settled around
 * then» would catch a second handover made the same afternoon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->nullable()->index();

            // Whose account was settled, and who took the money.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();

            // The seller's own request, where there was one. Null is the
            // owner settling somebody at the counter, which is the case
            // that had no record at all.
            $table->foreignId('settlement_request_id')->nullable()
                ->constrained()->nullOnDelete();

            // «تسویه» closed the sales it names; «پرداخت» is money against
            // an account that stayed open. They read differently in a
            // history and they reverse differently, so they are told apart
            // here rather than inferred from whether anything is left.
            $table->string('kind')->default('settlement');

            $table->decimal('paid_cash', 14, 2)->default(0);
            $table->decimal('paid_card', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);

            // Which account the card share reached, for naming it back.
            $table->foreignId('bank_account_id')->nullable()
                ->constrained()->nullOnDelete();

            // Exactly what this handover closed, and any credit row it
            // left behind — the two things a reversal has to put back.
            $table->json('sale_ids')->nullable();
            $table->json('credit_ids')->nullable();

            $table->string('note')->nullable();

            // Undone rather than deleted: a settlement that was wrong is
            // part of what happened, and the owner asking «why did this
            // change» deserves to find the answer rather than a gap.
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('reversal_reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_settlements');
    }
};
