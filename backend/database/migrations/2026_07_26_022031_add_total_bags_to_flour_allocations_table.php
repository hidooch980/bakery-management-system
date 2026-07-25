<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quotas are issued in sacks, so that is what the admin enters. The
     * weight is derived from the bag weight in bakery settings, but the
     * original sack count is kept so a later change to the bag weight
     * cannot silently rewrite history.
     */
    public function up(): void
    {
        Schema::table('flour_allocations', function (Blueprint $table) {
            $table->decimal('total_bags', 10, 2)->nullable()->after('month_label');
        });
    }

    public function down(): void
    {
        Schema::table('flour_allocations', function (Blueprint $table) {
            $table->dropColumn('total_bags');
        });
    }
};
