<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «بعد از اولین پیامک موجودی بروز بشه»: the balance the bank itself last
 * reported in an SMS, and when. Kept beside the ledger, never written into
 * it — no income, expense or transaction comes from this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->decimal('sms_balance', 16, 2)->nullable();
            $table->dateTime('sms_balance_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn(['sms_balance', 'sms_balance_at']);
        });
    }
};
