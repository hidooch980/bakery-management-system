<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person belonged to exactly one shop, because there was only ever one.
 *
 * `users.bakery_id` answered «which shop is this request about» for the
 * whole system — every global scope on every model goes through it. It
 * still does, and this migration does not touch it: it is the shop
 * somebody works at, their home, and the default for everything.
 *
 * What it could not answer is an owner with three shops. They needed
 * three sign-ins, and no screen anywhere could show the three together.
 *
 * So the extra shops are listed beside the column rather than replacing
 * it. Somebody with no row here behaves exactly as they did yesterday —
 * which is every member of staff in the shop, and the reason this can go
 * out on a working day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bakery_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Naming the same pair twice would let one shop appear twice
            // in a switcher, and the second row would be unreachable and
            // never noticed.
            $table->unique(['bakery_id', 'user_id']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bakery_user');
    }
};
