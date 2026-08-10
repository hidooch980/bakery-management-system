<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mail settings lived in .env, which meant changing them was an SSH session
 * and a text editor on the production box. The credential that was there
 * had expired and nobody could tell, because nobody who noticed the missing
 * backups could reach the file.
 *
 * The password column is encrypted by the model rather than stored plainly:
 * a settings table is read by more code, and more people, than .env ever was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->nullable()->index();

            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('encryption')->nullable()->default('tls');
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();

            // Where the nightly database backup is sent. Comma separated,
            // because a single inbox that fills up is a backup nobody has.
            $table->string('backup_mail_to')->nullable();

            // Off until someone has proved the settings work, so a bad
            // password does not fill the backup log with failures nightly.
            $table->boolean('backup_mail_enabled')->default(false);

            // What happened last time it was tried, so the admin can see
            // whether it works without reading a server log.
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_succeeded')->nullable();
            $table->text('last_test_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
