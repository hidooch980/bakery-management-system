<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Days the bakery is closed. Recorded so attendance and production
     * reports do not read a closed day as an absence or a shortfall.
     */
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('title');
            $table->enum('type', ['official', 'religious', 'shop'])->default('official');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique('date');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
