<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the bakery pays the mill for flour — distinct from
     * flour_price_per_kg, which is what the bakery charges a customer when
     * reselling flour out of its own warehouse.
     */
    public function up(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            // Stored in Toman, like every other amount in the system.
            $table->decimal('flour_purchase_price_per_kg', 14, 2)->nullable()
                ->after('flour_price_per_bag');

            // Whether the mill delivers as part of the purchase, or the
            // bakery has to arrange and pay for transport itself.
            $table->boolean('flour_transport_by_factory')->default(true)
                ->after('flour_purchase_price_per_kg');
        });
    }

    public function down(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->dropColumn(['flour_purchase_price_per_kg', 'flour_transport_by_factory']);
        });
    }
};
