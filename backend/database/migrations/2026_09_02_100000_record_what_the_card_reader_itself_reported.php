<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one figure this shop could never check against itself.
 *
 * «اختلاف با کارتخوان» has always compared the period's quota against
 * `card_bread_count` — and that is the shop's *own* record, the loaves a
 * seller marked as paid by card. Nothing has ever compared it with what
 * the card reader actually registered.
 *
 * That matters because the two are not the same authority. Next month's
 * flour follows what the national system saw, not what the shop wrote
 * down, so a seller marking a card sale as cash costs real flour and
 * leaves no trace anybody would notice until the allocation arrives
 * smaller than expected.
 *
 * Reading it automatically was the point of the nanino link, removed on
 * 1405/06/12 because their gateway refuses server-to-server calls. So it
 * is typed in: three numbers a month, off the screen he already opens.
 * Nullable, because a period nobody has checked must read as unchecked
 * rather than as zero — those are very different claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flour_allocation_periods', function (Blueprint $table) {
            $table->unsignedInteger('system_bread_count')->nullable()->after('allocated_kg');
        });
    }

    public function down(): void
    {
        Schema::table('flour_allocation_periods', function (Blueprint $table) {
            $table->dropColumn('system_bread_count');
        });
    }
};
