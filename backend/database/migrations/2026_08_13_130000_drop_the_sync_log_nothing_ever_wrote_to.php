<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes a table that was created and then never used.
 *
 * sync_logs came in with a phone-side backup feature that was rescued off
 * an old server: a model with no body, a controller with no methods and a
 * table nothing ever wrote a row to. Keeping an empty table teaches the
 * next reader that syncing is a thing this system does.
 *
 * The down() rebuilds it exactly, and there is no data to lose either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sync_logs');
    }

    public function down(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
