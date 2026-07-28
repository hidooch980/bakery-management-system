<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dormitories buy bread on the same terms as schools and offices, but
 * were being filed under "other" — so their debt could not be told apart
 * from a walk-in's when the seller went to collect.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE customers MODIFY type
             ENUM('school','office','dormitory','partner','other') NOT NULL DEFAULT 'school'");
    }

    public function down(): void
    {
        DB::table('customers')->where('type', 'dormitory')->update(['type' => 'other']);

        DB::statement("ALTER TABLE customers MODIFY type
             ENUM('school','office','partner','other') NOT NULL DEFAULT 'school'");
    }
};
