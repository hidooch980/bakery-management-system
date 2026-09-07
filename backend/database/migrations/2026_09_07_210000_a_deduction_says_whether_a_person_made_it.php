<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which adjustments the tariff made and which a person typed.
 *
 * The late tariff now writes its own deduction, and it rewrites it every
 * time a day changes. It must be able to find exactly the row it made
 * before — and it must never touch one somebody entered by hand.
 *
 * Matching on the reason text would do neither safely: `reason` is a free
 * field a person can type anything into, including whatever sentence this
 * happens to use.
 *
 * Null means a person made it, which is what every existing row is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_adjustments', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->after('basis');
            $table->index(['user_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('staff_adjustments', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'source']);
            $table->dropColumn('source');
        });
    }
};
