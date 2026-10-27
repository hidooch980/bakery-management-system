<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bale_settings', function (Blueprint $table) {
            $table->boolean('safir_enabled')->default(false);
            $table->text('safir_api_key')->nullable();
            $table->unsignedBigInteger('safir_bot_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bale_settings', function (Blueprint $table) {
            $table->dropColumn(['safir_enabled', 'safir_api_key', 'safir_bot_id']);
        });
    }
};
