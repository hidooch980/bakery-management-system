<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «ثبت خروج»: when someone left, so the hours worked can be counted.
 *
 * Nullable and additive: every existing row keeps its check-in and simply
 * has no check-out, which is exactly what was recorded at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('attendances', 'checked_out_at')) {
            return;
        }

        Schema::table('attendances', function (Blueprint $table) {
            $table->dateTime('checked_out_at')->nullable()->after('checked_in_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('attendances', 'checked_out_at')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropColumn('checked_out_at');
            });
        }
    }
};
