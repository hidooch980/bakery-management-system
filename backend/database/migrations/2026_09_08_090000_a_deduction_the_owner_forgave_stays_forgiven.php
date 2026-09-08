<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's decision to let a deduction go, remembered.
 *
 * The tariff writes its own row and rewrites it whenever a day changes.
 * That is what keeps the figure honest, and it is also what took the
 * owner's discretion away: forgiving somebody a late day meant deleting a
 * row that came straight back, so the delete was refused outright.
 *
 * A rule that cannot be waived is not a rule the shop is running — it is
 * one running the shop. «در پرداخت دستم باز باشه.»
 *
 * So the waiver is recorded rather than the row deleted: the tariff's
 * figure stays visible, and beside it the fact that somebody decided not
 * to take it, and who. Null on every row, which is every deduction as it
 * stands today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_adjustments', function (Blueprint $table) {
            $table->timestamp('waived_at')->nullable()->after('source');
            $table->foreignId('waived_by')->nullable()->after('waived_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('staff_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('waived_by');
            $table->dropColumn('waived_at');
        });
    }
};
