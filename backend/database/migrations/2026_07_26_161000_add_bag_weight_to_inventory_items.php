<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        // Written as literals rather than model constants: a migration
        // has to keep meaning what it meant the day it ran, and the model
        // moves on — dough has since stopped being a stocked good at all.
        DB::table('inventory_items')->where('key', 'salt')->update(['bag_weight_kg' => 25]);
        DB::table('inventory_items')->where('key', 'dough')->update(['bag_weight_kg' => 10]);
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('bag_weight_kg');
        });
    }
};
