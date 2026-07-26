<?php

use App\Models\InventoryItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Salt and dough are handled in fixed-size sacks too, same as flour —
     * just each its own size. Flour keeps reading the bakery-wide setting it
     * always has, so existing installs are unaffected; salt and dough get
     * their sizes here since nothing has ever asked before now.
     */
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->decimal('bag_weight_kg', 8, 3)->nullable()->after('unit');
        });

        InventoryItem::ofKey(InventoryItem::SALT)->update(['bag_weight_kg' => 25]);
        InventoryItem::ofKey(InventoryItem::DOUGH)->update(['bag_weight_kg' => 10]);
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('bag_weight_kg');
        });
    }
};
