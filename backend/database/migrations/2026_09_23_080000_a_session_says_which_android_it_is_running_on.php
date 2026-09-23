<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * گوشی روی کدام اندروید کار می‌کند.
 *
 * فهرست دستگاه‌ها مدتی است نام گوشی و بیلد را می‌گوید، و معلوم شد آن
 * دو سوم از سؤال است که جواب نمی‌دهد. فروشنده گفت APK تازه نصب
 * نمی‌شود؛ فهرست می‌گفت «Samsung SM-J250F» و نسخه‌ای سه انتشار
 * عقب‌تر، و هیچ‌کدام آن چیزی را نمی‌گفت که اهمیت داشت — گوشی روی
 * اندرویدی قدیمی‌تر از آنی بود که اپ از PR #7 به بعد لازم دارد، پس
 * هیچ نسخه‌ای از آن موقع تا حالا نمی‌توانسته رویش نصب شود، و هیچ‌کس
 * نمی‌توانست این را از هیچ صفحه‌ای بفهمد.
 *
 * پس نسخه کنار مدل می‌نشیند. `sdk_int` هم علاوه بر نام، چون ۲۴ عددی
 * است که بیلد واقعاً با آن مقایسه می‌شود و «۷.۰» عددی است که آدم
 * می‌شناسد — و درآوردنِ یکی از روی آن یکی، در هر صفحه‌ای که لازمش
 * داشته باشد، همان راهی است که این دو به اختلاف می‌رسند.
 *
 * مثل `app_version` کنارش، می‌تواند خالی باشد: توکنی که اپِ قدیمی‌تر
 * ساخته هرگز چیزی نمی‌فرستد، و ستونِ خالی خودش یک جواب است — آن گوشی
 * از وقتی این منتشر شده به‌روز نشده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // «Android 7.0»، «iOS 17.2» — آنچه آدم می‌خواند.
            $table->string('os_version', 30)->nullable()->after('app_version');

            // چیزی که minSdk با آن مقایسه می‌شود. روی iOS خالی است، و
            // روی هر اندرویدی که گزارشش نکرده باشد هم.
            $table->unsignedSmallInteger('sdk_int')->nullable()->after('os_version');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['os_version', 'sdk_int']);
        });
    }
};
