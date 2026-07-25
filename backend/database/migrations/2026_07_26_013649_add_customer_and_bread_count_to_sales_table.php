<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // How many loaves this sale covered.
            $table->unsignedInteger('bread_count')->nullable()->after('payment_type');
            // Which school or office bought it, when the payment type is one
            // that identifies a named buyer.
            $table->foreignId('customer_id')->nullable()->after('bread_count')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('bread_count');
        });
    }
};
