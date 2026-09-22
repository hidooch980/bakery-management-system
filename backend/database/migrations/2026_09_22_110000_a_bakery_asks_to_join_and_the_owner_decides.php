<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bakery asking to use this, and the answer.
 *
 * Selling the system needs a way in for people who are not yet in it.
 * That way in is a public door on a server that runs a working bakery,
 * which is the whole reason it is an *application* and not a signup:
 * nothing a stranger sends creates a shop, creates a login, or grants
 * anything at all. It creates a row that says somebody asked.
 *
 * The shop is opened afterwards, by the owner, through the same
 * `OpenBakery` action the panel and the console already use — so a shop
 * that arrived this way is identical to one opened by hand, and there is
 * no second path into existence to keep in step.
 *
 * The password is not here on purpose. A password typed by a stranger
 * into a form and held in a table until somebody gets round to reading
 * it is a password sitting in plain sight; the owner sets one when they
 * approve, or hands the shop to an account that already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bakery_applications', function (Blueprint $table) {
            $table->id();

            $table->string('bakery_name');
            $table->string('owner_name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('city')->nullable();
            $table->text('note')->nullable();

            // pending · approved · rejected
            $table->string('status')->default('pending');

            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('rejection_reason')->nullable();

            // The shop this became, once it became one.
            $table->foreignId('bakery_id')->nullable()
                ->constrained()->nullOnDelete();

            // Where it came from. Not for blocking anybody — for reading
            // afterwards, when the same address has sent forty of these.
            $table->string('ip')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bakery_applications');
    }
};
