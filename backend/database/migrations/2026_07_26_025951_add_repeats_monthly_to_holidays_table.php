<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A shop closure that falls on the same Jalali day every month can be
     * generated ahead instead of entered twelve times. Official and religious
     * holidays move around, so only shop closures may repeat.
     */
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->boolean('repeats_monthly')->default(false)->after('type');
            // Links a generated occurrence back to the rule that made it, so
            // regenerating never duplicates and the series can be removed.
            $table->foreignId('repeats_from_id')->nullable()->after('repeats_monthly')
                ->constrained('holidays')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repeats_from_id');
            $table->dropColumn('repeats_monthly');
        });
    }
};
