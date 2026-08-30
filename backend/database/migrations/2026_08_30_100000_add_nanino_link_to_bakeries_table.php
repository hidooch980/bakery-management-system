<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the shop's link to its own card terminal lives.
 *
 * The card reader is the only thing the flour quota is ever measured
 * against, and until now its figures reached this system by somebody
 * reading them off another website and typing them in. These columns hold
 * what is needed to read them directly.
 *
 * The mobile and national number identify the merchant to nanino and are
 * the owner's own; the token is a session, not a password — nanino signs
 * in with an SMS code and a captcha, so no password exists to store and
 * none is stored. The token column is encrypted at rest all the same: it
 * grants the same access while it lasts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->string('nanino_mobile', 20)->nullable();
            $table->string('nanino_national_number', 20)->nullable();
            $table->text('nanino_token')->nullable();
            $table->text('nanino_refresh_token')->nullable();
            $table->timestamp('nanino_connected_at')->nullable();
            // Why the last read failed, if it did. A link that stops
            // working must say so rather than keep showing yesterday's
            // figure as though it were today's.
            $table->string('nanino_last_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->dropColumn([
                'nanino_mobile',
                'nanino_national_number',
                'nanino_token',
                'nanino_refresh_token',
                'nanino_connected_at',
                'nanino_last_error',
            ]);
        });
    }
};
