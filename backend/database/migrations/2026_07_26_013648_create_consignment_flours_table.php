<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flour lent to or borrowed from a partner bakery. Tracked apart from
     * owned stock so the quota reporting stays honest.
     */
    public function up(): void
    {
        Schema::create('consignment_flours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('partner_name');
            $table->string('partner_phone')->nullable();
            // borrowed: they gave us flour. lent: we gave them flour.
            $table->enum('direction', ['borrowed', 'lent']);
            $table->decimal('amount_kg', 12, 3);
            $table->date('occurred_on');
            $table->date('settled_on')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['direction', 'settled_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_flours');
    }
};
