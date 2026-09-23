<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Android a handset is running.
 *
 * The device list has named the phone and the build for a while, and
 * that turned out to be the two thirds of the question that do not
 * answer it. A seller reported the new APK would not install; the list
 * said «Samsung SM-J250F» and an app version three releases old, and
 * neither said the thing that mattered — the phone was on an Android
 * older than the one the app has required since PR #7, so no release
 * since then could ever have installed on it, and nobody could have
 * known that from any screen.
 *
 * So the version goes beside the model. `sdk_int` as well as the name,
 * because 24 is the number the build is actually compared against and
 * «۷.۰» is the one a person recognises — and working one out from the
 * other in whichever screen happens to need it is how they come to
 * disagree.
 *
 * Nullable, like `app_version` beside it: a token minted by an older app
 * never sends one, and an empty column is itself an answer — that phone
 * has not been updated since this shipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // «Android 7.0», «iOS 17.2» — what the person reads.
            $table->string('os_version', 30)->nullable()->after('app_version');

            // What the minSdk is compared against. Null on iOS, and on
            // any Android that did not report it.
            $table->unsignedSmallInteger('sdk_int')->nullable()->after('os_version');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['os_version', 'sdk_int']);
        });
    }
};
