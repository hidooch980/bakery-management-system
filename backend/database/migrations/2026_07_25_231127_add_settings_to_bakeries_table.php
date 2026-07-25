<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference values the shop defines once and the app reuses when
     * recording chane and sales.
     */
    public function up(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->decimal('normal_chane_weight_kg', 8, 3)->nullable()->after('description');
            $table->decimal('nanino_chane_weight_kg', 8, 3)->nullable()->after('normal_chane_weight_kg');
            $table->decimal('bread_price', 12, 2)->nullable()->after('nanino_chane_weight_kg');
        });
    }

    public function down(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->dropColumn([
                'normal_chane_weight_kg',
                'nanino_chane_weight_kg',
                'bread_price',
            ]);
        });
    }
};
