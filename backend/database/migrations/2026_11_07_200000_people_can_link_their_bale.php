<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * هر همکار، کارمند و تأمین‌کننده می‌تواند یک بار شماره‌اش را به ربات بله
 * بدهد تا پیام‌ها رایگان از بله برسد. همهٔ کلیدهای تازه خاموش‌اند.
 */
return new class extends Migration
{
    private const PEOPLE = ['customers', 'users', 'suppliers'];

    public function up(): void
    {
        foreach (self::PEOPLE as $table) {
            Schema::table($table, function (Blueprint $t) {
                // شناسهٔ چت خصوصی بله؛ فقط وقتی خود شخص شماره‌اش را فرستاده.
                $t->string('bale_chat_id', 32)->nullable();
                $t->timestamp('bale_linked_at')->nullable();
                $t->index('bale_chat_id');
            });
        }

        Schema::table('bale_settings', function (Blueprint $t) {
            // نام کاربری عمومی ربات، برای پیوند و QR دعوت.
            $t->string('bot_username', 64)->nullable();
            // بخش مخفی نشانی وب‌هوک؛ نانوایی را از روی همین می‌شناسیم.
            $t->string('webhook_secret', 64)->nullable()->unique();
            // پذیرفتن شمارهٔ افراد در ربات — خاموش.
            $t->boolean('link_enabled')->default(false);
            // فرستادن پیام‌های خودکار از بله برای افراد متصل — خاموش.
            $t->boolean('deliver_via_bale')->default(false);
        });

        Schema::table('notification_settings', function (Blueprint $t) {
            // یادآوری بدهی به خود فروشنده — خاموش.
            $t->boolean('sms_seller_debt')->default(false);
        });

        // هر آپدیت بله فقط یک بار پردازش می‌شود.
        Schema::create('bale_updates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bakery_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('update_id');
            $t->string('outcome', 30);
            $t->timestamps();
            $t->unique(['bakery_id', 'update_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bale_updates');
        Schema::table('notification_settings', fn (Blueprint $t) => $t->dropColumn('sms_seller_debt'));
        Schema::table('bale_settings', function (Blueprint $t) {
            $t->dropUnique(['webhook_secret']);
            $t->dropColumn(['bot_username', 'webhook_secret', 'link_enabled', 'deliver_via_bale']);
        });
        foreach (self::PEOPLE as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['bale_chat_id']);
                $t->dropColumn(['bale_chat_id', 'bale_linked_at']);
            });
        }
    }
};
