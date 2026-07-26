<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a seller accounts for fewer loaves than the batch actually held,
     * the gap is a temporary debt against them until it is written off or
     * reconciled — recorded here rather than left as a UI-only notice.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedInteger('shortfall_count')->nullable()->after('bread_count');
            $table->decimal('shortfall_amount', 14, 2)->nullable()->after('shortfall_count');
            $table->date('shortfall_settled_on')->nullable()->after('shortfall_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['shortfall_count', 'shortfall_amount', 'shortfall_settled_on']);
        });
    }
};
