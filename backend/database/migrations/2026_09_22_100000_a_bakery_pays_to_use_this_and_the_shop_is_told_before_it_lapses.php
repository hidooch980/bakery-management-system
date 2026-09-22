<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a bakery has paid for, and until when.
 *
 * The system has run one shop, owned by the person who commissioned it,
 * and nothing in it has ever needed to know whether that shop was
 * entitled to run. Selling it to other bakeries makes that a question,
 * and a question nobody can answer from the database is one that gets
 * answered from memory.
 *
 * A row per term rather than a date on the bakery, so «until when» and
 * «what they have paid over two years» are the same record read two
 * ways. A renewal is a new row; the current term is the latest one that
 * has started.
 *
 * Nothing here switches anything off. Deciding what an expired shop may
 * still do is a separate matter with a shop floor at the end of it, and
 * it is not settled by a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            // Not nullable and not scoped by the tenant trait: this is
            // the one table that is *about* bakeries rather than inside
            // one, and a shop must never be able to read or write its
            // own entitlement through the ordinary scope.
            $table->foreignId('bakery_id')->constrained()->cascadeOnDelete();

            $table->string('plan')->default('standard');

            $table->date('starts_on');
            $table->date('ends_on');

            // What they actually paid for this term, in the system's own
            // unit. Nullable because the first shops on it did not pay.
            $table->decimal('amount', 14, 2)->nullable();

            // Who recorded it, for the same reason every other money row
            // carries it.
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('note')->nullable();

            // Ended early — refunded, or the bakery left. Kept rather
            // than deleted so the history reads true.
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->timestamps();

            $table->index(['bakery_id', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
