<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Until now a settlement closed the seller's whole account, so there was
 * nothing to record: the answer was always "everything open at the time".
 * A seller handing over what they can afford today needs the request to say
 * which debts the money covers, or confirming it later would clear sales the
 * two of them never discussed.
 *
 * Null keeps meaning the whole account, so requests already in the table —
 * and any sent by a copy of the app that predates this — settle as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_requests', function (Blueprint $table) {
            $table->json('sale_ids')->nullable()->after('paid_breakdown');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_requests', function (Blueprint $table) {
            $table->dropColumn('sale_ids');
        });
    }
};
