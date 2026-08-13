<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asking for an advance, which until now happened in the doorway.
 *
 * Kept apart from `staff_advances` on purpose. That table means money that
 * has left the till: it posts to a bank account, and payslips deduct
 * against it. A request is none of those things until someone says yes, so
 * putting the two in one table would post cash that never moved and dock
 * pay for an advance nobody handed over. Approving a request creates the
 * advance and points back at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_advance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->constrained()->cascadeOnDelete();
            // Who is asking. Always the signed-in user: nobody asks on
            // another person's behalf.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Stored in Toman like every other amount in the system.
            $table->decimal('amount', 14, 2);
            $table->text('reason')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected'])
                ->default('pending');

            $table->foreignId('decided_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            // Why it was turned down, so the answer is not a bare "no".
            $table->text('decision_note')->nullable();

            // The advance this became, once it was granted.
            $table->foreignId('staff_advance_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->timestamps();

            // The two questions asked of this table: "what is waiting on me"
            // and "what have I asked for".
            $table->index(['bakery_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_advance_requests');
    }
};
