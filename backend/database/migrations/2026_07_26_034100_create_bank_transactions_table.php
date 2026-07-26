<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('direction', ['in', 'out']);
            // Stored in Toman like every other amount in the system.
            $table->decimal('amount', 14, 2);
            $table->string('reason')->default('manual');
            // Links back to the sale, expense or salary that moved the money.
            $table->nullableMorphs('source');
            $table->date('occurred_on');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['bank_account_id', 'direction']);
            $table->index('occurred_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
