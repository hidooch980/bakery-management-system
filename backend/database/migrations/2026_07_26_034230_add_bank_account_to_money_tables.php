<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money that actually moved through an account can name which one, so a
     * bank balance can be reconciled against the sales and costs behind it.
     *
     * Nullable throughout: cash never touches a bank, and rows entered before
     * accounts existed must stay valid.
     */
    public function up(): void
    {
        foreach (['sales', 'expenses', 'salary_payments'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('bank_account_id')->nullable()
                    ->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['sales', 'expenses', 'salary_payments'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('bank_account_id');
            });
        }
    }
};
