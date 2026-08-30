<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A third way for the nightly backup to leave the machine — mirrors
 * telegram_settings. Bale's bot API is deliberately Telegram-compatible
 * (same base shape, same 50 MB document cap), and it is the one of the
 * three that is not filtered inside Iran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bale_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->nullable()->index();

            $table->text('bot_token')->nullable();
            $table->string('chat_id')->nullable();

            $table->boolean('backup_bale_enabled')->default(false);

            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_succeeded')->nullable();
            $table->text('last_test_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bale_settings');
    }
};
