<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credit sales are money owed until they are collected. Without a
     * settlement date there is no way to tell this month's outstanding debt
     * from a debt carried since last year.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->date('settled_on')->nullable()->after('amount');
            $table->index('settled_on');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['settled_on']);
            $table->dropColumn('settled_on');
        });
    }
};
