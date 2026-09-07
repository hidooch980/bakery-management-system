<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which version of the app a signed-in phone is actually running.
 *
 * The device list has said «Samsung SM-A546E» since it was written, and
 * nothing anywhere said what was installed on it. So «کار نکرد» from the
 * shop floor could not be told apart from «کار نکرد, on a build from three
 * releases ago» — and four releases in a row were spent fixing things that
 * may not have been on the handset doing the complaining.
 *
 * Twenty characters: a semantic version with room for the build metadata
 * the updater already appends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('app_version', 20)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('app_version');
        });
    }
};
