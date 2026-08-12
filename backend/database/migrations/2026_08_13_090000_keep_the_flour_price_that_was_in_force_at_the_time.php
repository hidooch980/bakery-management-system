<?php

use App\Models\Bakery;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records what flour cost from when, instead of one figure that rewrites
 * history.
 *
 * The bakery carried a single flour_purchase_price_per_kg, and the cost of
 * goods read it for every period. Put today's higher price in and last
 * month's bread suddenly cost more to bake than it did — a settled month's
 * profit changing under the owner's feet, and the partners' split with it.
 *
 * Each row says "from this date, flour cost this". The cost of a bake is
 * the price in force on the day it happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flour_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->nullable()->index();
            $table->decimal('price_per_kg', 14, 2);
            $table->date('effective_from');
            $table->string('note')->nullable();
            $table->timestamps();

            // The price in force on a day is the newest row not after it.
            $table->index(['bakery_id', 'effective_from']);
        });

        // Whatever the shop is using now becomes the opening price, dated
        // early enough to cover every bake already on file. Without this
        // the first report after the migration would cost historic flour
        // at nothing.
        foreach (Bakery::query()->get() as $bakery) {
            $price = (float) ($bakery->flour_purchase_price_per_kg ?? 0);

            if ($price <= 0) {
                continue;
            }

            DB::table('flour_prices')->insert([
                'bakery_id' => $bakery->id,
                'price_per_kg' => $price,
                'effective_from' => '2000-01-01',
                'note' => 'قیمت ثبت‌شده پیش از تاریخ‌دار شدن',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flour_prices');
    }
};
