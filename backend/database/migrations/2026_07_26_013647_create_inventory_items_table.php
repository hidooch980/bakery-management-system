<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock-keeping units the bakery holds: flour, salt and prepared dough.
     * Balances are derived from movements rather than stored, so the ledger
     * and the balance can never disagree.
     */
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();   // flour | salt | dough
            $table->string('name');
            $table->string('unit')->default('kg');
            $table->decimal('low_threshold', 12, 3)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
