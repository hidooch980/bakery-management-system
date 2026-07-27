<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How the seller handed the money over.
     *
     * Cash and a card transfer settle the same debt but arrive by different
     * routes, and the shop needs to know which — cash lands in the till, a
     * card payment lands in the bank. Recording both as one figure loses
     * that, and the two are reconciled against different places.
     */
    public function up(): void
    {
        Schema::table('settlement_requests', function (Blueprint $table) {
            $table->decimal('paid_cash', 14, 2)->default(0)->after('shortfall_amount');
            $table->decimal('paid_card', 14, 2)->default(0)->after('paid_cash');
            $table->foreignId('bank_account_id')->nullable()
                ->after('paid_card')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('settlement_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropColumn(['paid_cash', 'paid_card']);
        });
    }
};
