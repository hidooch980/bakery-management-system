<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پیام فوری به همکار هنگام ورود و خروج آرد امانی.
 *
 * هر تغییر اینجا ثبت و دو دقیقه بعد، برای هر همکار در یک پیام، فرستاده
 * می‌شود. کلید آن خاموش است و تا روشن نشود هیچ ردیفی ثبت نمی‌شود — پس
 * روشن‌کردن کلید برای ثبت‌های گذشته پیامی نمی‌فرستد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $t) {
            $t->boolean('partner_flow_enabled')->default(false);
        });

        Schema::create('partner_flour_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bakery_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // flour: ردیف آرد امانی؛ return: برگشت بخشی آن.
            $t->string('subject_type', 10);
            $t->unsignedBigInteger('subject_id');
            // created / updated / deleted / settled / unsettled
            $t->string('kind', 12);
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            // pending → sending → sent، یا dropped وقتی چیزی برای گفتن نماند.
            $t->string('status', 10)->default('pending');
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['bakery_id', 'status', 'customer_id']);
            $t->index(['subject_type', 'subject_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_flour_changes');
        Schema::table('notification_settings', fn (Blueprint $t) => $t->dropColumn('partner_flow_enabled'));
    }
};
