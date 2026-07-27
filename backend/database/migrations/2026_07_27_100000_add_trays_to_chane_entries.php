<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chane is shaped and counted a tray at a time, so that is how the
     * chane gir records it: first tray, second tray, and so on.
     *
     * Each tray's own count is kept rather than just how many trays there
     * were, because the last tray of a batch is usually not full. Storing
     * only a tray count and multiplying by a standard size would round
     * every batch off, and the production checks that compare bread
     * against the flour it consumed would then raise false alarms.
     */
    public function up(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->unsignedInteger('chane_per_tray')->nullable()->after('nanino_chane_weight_kg');
        });

        Schema::table('chane_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('tray_count')->nullable()->after('chane_count');
            $table->json('tray_counts')->nullable()->after('tray_count');
        });
    }

    public function down(): void
    {
        Schema::table('bakeries', function (Blueprint $table) {
            $table->dropColumn('chane_per_tray');
        });

        Schema::table('chane_entries', function (Blueprint $table) {
            $table->dropColumn(['tray_count', 'tray_counts']);
        });
    }
};
