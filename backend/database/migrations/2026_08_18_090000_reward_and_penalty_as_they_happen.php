<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rewards and penalties, written down on the day they are earned.
 *
 * The payslip has always had a «پاداش» and a «کسورات» box, and both are
 * typed at the moment of payment — which is the end of a long month, when
 * nobody remembers who came in late on the 12th or who worked the extra
 * shift on the 20th. A figure recalled at payday is a figure guessed at.
 *
 * So each one is recorded when it happens, with a reason and a date, and
 * the month's total is what the pay sheet opens on. The owner can still
 * change the figure before he pays — see the controller for why it is
 * pre-filled rather than added on the server: a wage confirmed at one
 * number and stored at another is the bug this shop spent 2026-08-17
 * finding.
 *
 * Three bases, because the owner asked for all three:
 *
 *   - `amount` — a sum in the shop's own unit
 *   - `days`   — half a day, one day; priced from the monthly wage so the
 *                same rule gives a different figure for different people
 *   - `note`   — on the record and worth nothing, for when saying it is
 *                the whole point
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_adjustments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('kind', 16);   // reward | penalty
            $table->string('basis', 16);  // amount | days | note

            // Stored in Toman like every other figure here. Null on a
            // days-based or note-only entry, which is not the same as zero:
            // one has no money attached, the other is worth nothing.
            $table->decimal('amount', 15, 2)->nullable();
            $table->decimal('days', 5, 2)->nullable();

            $table->date('occurred_on');

            // Why. Not optional: a deduction nobody can explain a month
            // later is one the person it was taken from will dispute, and
            // they will be right to.
            $table->string('reason', 300);

            // The payslip that settled it, once one has. Null while it is
            // still waiting for the end of the month.
            $table->foreignId('salary_payment_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->foreignId('bakery_id')->nullable()->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'occurred_on']);
            $table->index(['salary_payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_adjustments');
    }
};
