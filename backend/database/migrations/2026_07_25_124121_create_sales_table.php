<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chane_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('payment_type', ['cash', 'card', 'credit', 'home', 'schools', 'other']);
            $table->decimal('amount', 10, 2)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('payment_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
