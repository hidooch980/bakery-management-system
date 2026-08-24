<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to remember a write that has already been done.
 *
 * The phone queues anything it could not send and replays it later. It
 * decides "could not send" from the Dio exception type, and two of the
 * types it treats as never-arrived — receiveTimeout and sendTimeout —
 * mean the opposite: the request reached the server and very likely ran,
 * and only the answer was lost. Replaying one of those records the sale
 * a second time, with no error anywhere and no way to tell the duplicate
 * from a real second sale of the same bread at the same minute.
 *
 * So the phone now names each write, once, before its first attempt, and
 * uses the same name on every retry. This table is where that name is
 * kept along with what the server answered, so the retry can be given
 * the original answer instead of doing the work again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotent_requests', function (Blueprint $table) {
            $table->id();

            // The client's own uuid. Unique across the whole table and not
            // per user: a key is a name for one write, and the same name
            // arriving under two users is a bug worth failing loudly on
            // rather than quietly serving one of them the other's answer.
            $table->string('idempotency_key', 64)->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What was asked for. Kept so a key reused for a *different*
            // request can be told apart from an honest retry — the same
            // name on different work is a client bug, not a duplicate.
            $table->string('method', 10);
            $table->string('path');
            $table->string('body_hash', 64);

            $table->unsignedSmallInteger('status_code');
            $table->json('response');

            $table->timestamps();

            // Replays arrive within minutes; the row is worth keeping for
            // a while after that only so a late retry from a phone that
            // was off overnight still lands on it. Pruned by age.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotent_requests');
    }
};
