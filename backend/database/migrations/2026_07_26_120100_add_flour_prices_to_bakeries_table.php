<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            // Stored in Toman, like every other amount in the system.
            $table->decimal('flour_price_per_kg', 14, 2)->nullable()->after('bread_price');

            // Optional: when null, the sack price is derived from the kilo
            // price so the two can never quietly disagree.
            $table->decimal('flour_price_per_bag', 14, 2)->nullable()->after('flour_price_per_kg');
        });
    }

    public function down(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->dropColumn(['flour_price_per_kg', 'flour_price_per_bag']);
        });
    }
};
