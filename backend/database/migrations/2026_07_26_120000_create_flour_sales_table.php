<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flour_sales', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()
                ->constrained()->nullOnDelete();

            // Flour is sold either loose by the kilo or by the whole sack.
            $table->string('unit', 10)->default('kg');
            $table->decimal('quantity', 12, 3);

            // The sack weight is captured at sale time, so changing the
            // bakery setting later cannot rewrite past sales.
            $table->decimal('bag_weight_kg', 8, 3)->nullable();

            // Both derived: weight from quantity, amount from unit price.
            $table->decimal('weight_kg', 12, 3);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);

            $table->string('payment_type', 20)->default('cash');
            $table->date('sold_on');
            $table->date('settled_on')->nullable();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->index(['sold_on', 'payment_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flour_sales');
    }
};
