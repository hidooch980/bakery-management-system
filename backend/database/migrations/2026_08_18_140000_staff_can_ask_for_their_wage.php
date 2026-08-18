<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member of staff asking to be paid for the month.
 *
 * Not a payslip until it is answered — the same reasoning that keeps
 * `staff_advance_requests` out of `staff_advances`. A payslip means money
 * out of an account, advances recovered, and a month's rewards and
 * penalties settled. A request that sat in the same table would do all
 * three before anyone had said yes.
 *
 * No amount is asked for. The wage for the month is not the employee's to
 * propose — it is what was agreed, less what he has drawn, plus or minus
 * what the month came to. Asking him to type a figure would invite a
 * negotiation over a number the system already knows, and set him up to be
 * told he was wrong.
 *
 * What he is doing is saying «I have not been paid», and that is worth
 * recording plainly: this shop wrote no payslip at all in its first three
 * weeks, and nobody had any way to say so except in person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_payment_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The Jalali month being asked about, stored as its first day —
            // the same key the payslip uses, so the two can find each other.
            $table->date('period_start');
            $table->string('period_label', 40)->nullable();

            $table->string('status', 16)->default('pending');

            $table->string('note', 300)->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();

            // Filled when a payslip answers it. The payment is the answer:
            // there is no separate approve button that could write a wage
            // nobody looked at.
            $table->foreignId('salary_payment_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->foreignId('bakery_id')->nullable()->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_payment_requests');
    }
};
