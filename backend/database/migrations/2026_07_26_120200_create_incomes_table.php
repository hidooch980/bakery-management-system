<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incomes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();

            $table->string('category', 30)->default('other');
            $table->string('title');
            $table->decimal('amount', 14, 2);
            $table->date('received_on');
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->index(['received_on', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incomes');
    }
};
