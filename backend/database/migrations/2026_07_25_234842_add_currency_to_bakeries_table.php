<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Amounts stay stored in Toman; this only controls how they are displayed.
     */
    public function up(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->enum('currency', ['toman', 'rial'])->default('toman')->after('bread_price');
        });
    }

    public function down(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
