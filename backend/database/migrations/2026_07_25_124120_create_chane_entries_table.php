<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chane_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dough_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chane_count');
            $table->decimal('normal_weight_kg', 8, 2);
            $table->decimal('nanino_weight_kg', 8, 2);
            $table->decimal('spray_flour_kg', 8, 2);
            $table->string('status')->default('pending'); // pending | sold
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chane_entries');
    }
};
