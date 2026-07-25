<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flour carried over from earlier periods — the "سنوات" balance.
     *
     * It is tracked apart from the month's own quota so reporting can show
     * what was granted this month versus what was already owed, which matters
     * when the system starts partway through a year with a balance already
     * accumulated.
     */
    public function up(): void
    {
        Schema::table('flour_allocations', function (Blueprint $table) {
            $table->decimal('carryover_bags', 10, 2)->default(0)->after('total_bags');
            $table->decimal('carryover_kg', 12, 3)->default(0)->after('total_kg');
            $table->string('carryover_note')->nullable()->after('carryover_kg');
        });
    }

    public function down(): void
    {
        Schema::table('flour_allocations', function (Blueprint $table) {
            $table->dropColumn(['carryover_bags', 'carryover_kg', 'carryover_note']);
        });
    }
};
