<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('salary_payments', function (Blueprint $table) {
            // رفتار فیش‌ها و اپ‌های قبلی حفظ می‌شود.
            $table->boolean('recover_advances')->default(true);
            $table->boolean('recover_bread')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('salary_payments', fn (Blueprint $table) => $table->dropColumn(['recover_advances', 'recover_bread']));
    }
};
