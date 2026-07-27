<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A seller saying "I have handed this over", waiting for the admin to
     * agree that they did.
     *
     * The seller cannot clear their own account — that would undo the point
     * of recording the debt — but they are the one who knows when the money
     * changed hands, so they start the exchange and the admin closes it.
     *
     * The amount is captured when the request is made rather than read back
     * at confirmation time, so a sale recorded in between cannot quietly
     * change what was agreed.
     */
    public function up(): void
    {
        Schema::create('settlement_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 14, 2);
            $table->decimal('cash_amount', 14, 2)->default(0);
            $table->decimal('difference_amount', 14, 2)->default(0);
            $table->decimal('shortfall_amount', 14, 2)->default(0);

            $table->text('note')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            // Every screen asks the same thing: what is this seller waiting
            // on right now.
            $table->index(['user_id', 'confirmed_at', 'rejected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_requests');
    }
};
