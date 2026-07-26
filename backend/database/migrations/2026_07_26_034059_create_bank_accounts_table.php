<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bank and cash accounts the bakery's money sits in.
     *
     * The balance is not stored: it is the opening balance plus the movement
     * ledger, so the two can never drift apart.
     */
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('card_number')->nullable();
            $table->string('iban')->nullable();
            // What was in the account when it was added to the system.
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
