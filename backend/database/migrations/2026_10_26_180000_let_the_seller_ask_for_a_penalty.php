<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A seller asking for a penalty on a member of staff.
 *
 * Apart from `staff_adjustments` for the same reason advance requests are
 * apart from advances: a row there is counted on the payslip, and nothing
 * should be docked before the manager has said yes. Approving creates the
 * adjustment and points back at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_penalty_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            // fine = docked from pay; warning = on the record, worth nothing.
            $table->enum('type', ['fine', 'warning']);
            $table->string('reason', 32);
            $table->decimal('amount', 14, 2)->nullable();
            $table->text('note')->nullable();
            $table->date('occurred_on');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('staff_adjustment_id')->nullable()
                ->constrained('staff_adjustments')->nullOnDelete();
            $table->timestamps();
            $table->index(['bakery_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_penalty_requests');
    }
};
