<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Partner bakeries become defined records rather than a name retyped on
     * every transfer, so the same partner's history can be totalled.
     *
     * The free-text name stays as a fallback for rows entered before this and
     * for one-off partners the admin has not defined.
     */
    public function up(): void
    {
        Schema::table('consignment_flours', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
            $table->string('partner_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('consignment_flours', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
