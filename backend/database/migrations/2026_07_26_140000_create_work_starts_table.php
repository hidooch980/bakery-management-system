<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_starts', function (Blueprint $table) {
            $table->id();

            $table->string('type', 20);
            $table->date('date');
            $table->dateTime('started_at');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Whether the deadline was missed, and by how much, decided once
            // at tick time. The deadline setting may change later, and that
            // must not silently rewrite whether someone was late in the past.
            $table->boolean('is_late')->default(false);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->time('deadline')->nullable();

            $table->string('note', 500)->nullable();
            $table->timestamps();

            // One start per activity per day.
            $table->unique(['type', 'date'], 'work_start_unique');
            $table->index(['date', 'is_late']);
        });

        Schema::table('bakeries', function (Blueprint $table) {
            $table->time('chane_start_deadline')->nullable()->after('calendar');
            $table->time('baking_start_deadline')->nullable()->after('chane_start_deadline');
        });
    }

    public function down(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->dropColumn(['chane_start_deadline', 'baking_start_deadline']);
        });

        Schema::dropIfExists('work_starts');
    }
};
