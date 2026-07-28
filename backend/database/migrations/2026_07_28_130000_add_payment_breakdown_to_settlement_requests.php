<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a handover was made, type by type.
 *
 * paid_cash and paid_card said whether the money came by hand or through
 * the reader, but the shop settles in more ways than two — and the admin
 * counting it wants the same breakdown the seller counted out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_requests', function (Blueprint $table) {
            $table->json('paid_breakdown')->nullable()->after('paid_card');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_requests', function (Blueprint $table) {
            $table->dropColumn('paid_breakdown');
        });
    }
};
