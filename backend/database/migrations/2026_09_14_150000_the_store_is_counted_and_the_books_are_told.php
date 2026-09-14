<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * شمارش انبار، قرینهٔ شمارش صندوق.
 *
 * ۱۴۰۵/۰۶/۲۳ موجودی آرد دو بار با دست اصلاح شد، هر بار با یک دستور روی
 * سرور. دفتر آرد نشان داد بار اول نبوده: پنج اصلاح پیش از آن هم بود، هر
 * پنج تا رو به بالا. کاری که پنج بار با دست انجام شده، صفحه می‌خواهد.
 *
 * دلیل حرکت انبار `stocktake` از قبل وجود داشت و توضیحش هم نوشته شده بود
 * — «آنچه قفسه در روزی که کسی شمردش واقعاً داشت». چیزی که نبود، جایی بود
 * که بپرسد چند تا شمردی.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->nullable()->index();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // هر دو به واحد پایهٔ همان کالا — کیلوگرم برای آرد. کیسه چیزی
            // است که روی صفحه گفته و گرفته می‌شود، نه چیزی که ذخیره شود:
            // وزن کیسه در تنظیمات عوض می‌شود و آن‌وقت شمارش‌های قدیمی
            // معنی تازه‌ای پیدا می‌کنند.
            $table->decimal('counted_quantity', 14, 3);
            $table->decimal('expected_quantity', 14, 3);

            $table->timestamp('counted_at');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['inventory_item_id', 'counted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_counts');
    }
};
