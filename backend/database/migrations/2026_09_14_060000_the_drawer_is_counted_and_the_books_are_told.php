<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is actually in the drawer, written down next to what should be.
 *
 * The till has had a balance since it was flagged, and every hole closed
 * since then has been about money reaching it. None of that says whether
 * the figure is true: notes are handed over in a hurry, change is given
 * from the same drawer, and a sale typed at the wrong price leaves a gap
 * that no ledger can see because both sides of it agree.
 *
 * A gap found the same evening is a question somebody can answer — who
 * was on the counter, what was sold around then. The same gap found at
 * month end is a number, and nobody can do anything with it.
 *
 * The count is recorded whether or not the books are corrected to match.
 * Correcting silently would hide exactly what this is for; not recording
 * the count at all would lose the only evidence that the drawer was ever
 * right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // What was counted, and what the books said at that moment.
            // The expected figure is stored rather than recomputed: a
            // count is a statement about one instant, and recomputing it
            // later against a ledger that has moved on would rewrite
            // history every time somebody opened the page.
            $table->decimal('counted_amount', 15, 2);
            $table->decimal('expected_amount', 15, 2);

            $table->timestamp('counted_at');
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['bank_account_id', 'counted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_counts');
    }
};
