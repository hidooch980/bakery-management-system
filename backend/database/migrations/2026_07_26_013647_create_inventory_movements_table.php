<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->decimal('quantity', 12, 3);
            // Why the stock moved, e.g. purchase, production, waste.
            $table->string('reason')->default('manual');
            // Links the movement back to the dough or chane entry that caused it.
            $table->nullableMorphs('source');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['inventory_item_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
