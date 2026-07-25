<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('category', [
                'flour',      // خرید آرد
                'fuel',       // سوخت
                'utilities',  // آب، برق، گاز
                'rent',       // اجاره
                'maintenance',// تعمیرات
                'salary',     // حقوق (mirrored from salary_payments)
                'other',      // سایر
            ]);
            $table->string('title');
            // Stored in Toman, like every other amount in the system.
            $table->decimal('amount', 14, 2);
            $table->date('spent_on');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['category', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
