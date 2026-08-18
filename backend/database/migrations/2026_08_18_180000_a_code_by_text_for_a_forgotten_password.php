<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes sent by text to someone who has forgotten their password.
 *
 * Laravel's own `password_reset_tokens` is keyed by email and assumes a
 * link in a message. Nobody at this shop reads email; they have phones,
 * and half of them would not recognise a reset link if it arrived. So this
 * is a six-digit code, read off a text and typed in.
 *
 * The code itself is **hashed**, not stored. A reset table in plain text
 * is a list of live keys to every account in the shop — anyone who can
 * read the database can walk in as the owner, and a database is read by
 * more people than a password ever is: backups, dumps, a support session.
 *
 * `attempts` and `used_at` are what stop six digits from being guessable
 * and a code from being spent twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_codes', function (Blueprint $table) {
            $table->id();

            // The phone rather than the user, so a code can be issued for a
            // number that turns out not to belong to anyone — which is what
            // the flow does deliberately, to avoid confirming who is
            // registered here and who is not.
            $table->string('phone', 20)->index();

            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('code_hash');

            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            // Kept so a burst from one place is visible afterwards. Not
            // used to decide anything at the time: the rate limit is on the
            // phone number, because that is what costs the shop money and
            // wakes somebody up at night.
            $table->string('requested_ip', 45)->nullable();

            $table->timestamps();

            $table->index(['phone', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_codes');
    }
};
