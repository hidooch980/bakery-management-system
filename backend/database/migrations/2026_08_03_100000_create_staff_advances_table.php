<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_advances', function (Blueprint $table) {
            $table->id();
            // Who took the money.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Which admin handed it over.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            // Stored in Toman like every other amount in the system.
            $table->decimal('amount', 14, 2);
            $table->date('paid_on');
            // The account the cash left, if it came out of one.
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'paid_on']);
        });

        // How much of an advance each payslip took back. An advance larger
        // than a month's pay is recovered across several payslips, so this
        // is a row per payslip rather than a figure on the advance — the
        // amount still owed is derived from these, never stored, so deleting
        // one payslip cannot disturb what another one recovered.
        Schema::create('salary_advance_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_advance_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            // Named explicitly: the generated name overruns MySQL's limit.
            $table->unique(['salary_payment_id', 'staff_advance_id'], 'salary_advance_unique');
        });

        Schema::table('salary_payments', function (Blueprint $table) {
            // Kept apart from `deduction` so the hand-entered figure and the
            // automatic advance recovery can never be mistaken for each
            // other, and re-saving a payslip cannot double-count either.
            $table->decimal('advance_deduction', 14, 2)
                ->default(0)
                ->after('deduction');
        });
    }

    public function down(): void
    {
        Schema::table('salary_payments', function (Blueprint $table) {
            $table->dropColumn('advance_deduction');
        });

        Schema::dropIfExists('salary_advance_recoveries');
        Schema::dropIfExists('staff_advances');
    }
};
