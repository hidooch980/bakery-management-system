<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // First day of the Jalali month this payment covers, stored as a
            // Gregorian date so it can be range-queried like any other date.
            $table->date('period_start');
            $table->string('period_label');
            $table->decimal('base_amount', 14, 2)->default(0);
            $table->decimal('bonus', 14, 2)->default(0);
            $table->decimal('deduction', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2)->default(0);
            $table->date('paid_on')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period_start']);
            $table->index('paid_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_payments');
    }
};
