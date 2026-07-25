<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The monthly government flour quota, split across the three delivery
     * periods the shop works to: days 5-14, 15-24, and 25 to the 4th of the
     * following month.
     */
    public function up(): void
    {
        Schema::create('flour_allocations', function (Blueprint $table) {
            $table->id();
            // First day of the Jalali month this quota belongs to.
            $table->date('month_start');
            $table->string('month_label');
            $table->decimal('total_kg', 12, 3);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique('month_start');
        });

        Schema::create('flour_allocation_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flour_allocation_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('period_number'); // 1, 2 or 3
            $table->string('label');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('allocated_kg', 12, 3);
            $table->timestamps();

            // Named explicitly: the generated name exceeds MySQL's 64-char limit.
            $table->unique(['flour_allocation_id', 'period_number'], 'alloc_period_unique');
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flour_allocation_periods');
        Schema::dropIfExists('flour_allocations');
    }
};
