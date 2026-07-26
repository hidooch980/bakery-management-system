<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The unit column is shown to the user as-is everywhere (panel, mobile
     * app), so the stored value should already be Persian rather than
     * needing translation at every display site.
     */
    public function up(): void
    {
        DB::table('inventory_items')->where('unit', 'kg')->update(['unit' => 'کیلوگرم']);
    }

    public function down(): void
    {
        DB::table('inventory_items')->where('unit', 'کیلوگرم')->update(['unit' => 'kg']);
    }
};
