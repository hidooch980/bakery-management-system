<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "partner" was added to Customer::TYPES when partner bakeries became
     * definable, but the column enum was never widened to match — so saving
     * a partner customer failed with a truncation error.
     *
     * Raw SQL because doctrine/dbal cannot change an enum in place.
     */
    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE customers MODIFY COLUMN type
                 ENUM('school','office','partner','other') NOT NULL DEFAULT 'school'"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Anything already marked as a partner has to land somewhere.
            DB::table('customers')->where('type', 'partner')->update(['type' => 'other']);

            DB::statement(
                "ALTER TABLE customers MODIFY COLUMN type
                 ENUM('school','office','other') NOT NULL DEFAULT 'school'"
            );
        }
    }
};
