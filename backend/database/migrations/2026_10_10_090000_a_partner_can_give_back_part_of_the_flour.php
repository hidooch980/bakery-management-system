<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * برگشتِ بخشی از آرد امانی.
 *
 * تا امروز هر ردیف آرد امانی فقط یک «تاریخ تسویه» داشت: یا همه‌اش برگشته
 * بود یا هیچ‌اش. همکاری که از ۲۰ کیسه ۱۰ تا را پس می‌آورد جایی برای ثبت
 * نداشت. این جدول فقط اضافه می‌شود؛ هیچ ردیف قدیمی بازنویسی نمی‌شود و
 * ردیف‌های تسویه‌شدهٔ قبلی همچنان «برگشت کامل» در همان تاریخ تسویه خوانده
 * می‌شوند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consignment_flour_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('consignment_flour_id')->constrained('consignment_flours')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('bags', 10, 2);
            $table->date('returned_on');
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['consignment_flour_id', 'returned_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_flour_returns');
    }
};
