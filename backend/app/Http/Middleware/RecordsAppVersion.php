<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ثبت می‌کند یک نشست روی کدام بیلدِ اپ و کدام اندروید کار می‌کند، هر
 * وقت که یکی از این دو عوض شود.
 *
 * در هر درخواست، نه فقط موقع ورود — چون بعد از آپدیت کسی دوباره وارد
 * نمی‌شود و توکن از نصب عمر بیشتری دارد. فیلدی که بگوید ۵.۱.۰ در حالی
 * که گوشی روی ۵.۲.۰ است، از فیلد خالی بدتر است: با اطمینانِ یک
 * واقعیت غلط است، و درست همان روزی خوانده می‌شود که کسی دارد می‌فهمد
 * چرا یک صفحه خالی است.
 *
 * نوشتن فقط وقتی اتفاق می‌افتد که مقدار فرق کرده باشد، پس حالت
 * معمولی فقط مقایسهٔ دو رشته است روی ستونی که همراه توکن بارگذاری شده.
 *
 * هیچ‌چیزِ اینجا نمی‌تواند جلوی یک درخواست را بگیرد. این اعداد برای
 * کسی است که دارد مشکلی را ریشه‌یابی می‌کند؛ گوشی‌ای که هدرِ خراب
 * بفرستد، یا اصلاً نفرستد، هنوز نان می‌فروشد.
 */
class RecordsAppVersion
{
    /** به‌اندازهٔ یک نسخهٔ معنایی و متادیتای بیلد. */
    private const MAX = 20;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // ‏`currentAccessToken` برای درخواستی که با نگهبانِ نشست می‌آید
        // — یعنی پنل — یک TransientToken می‌دهد، و آن یک شیء ساده است
        // نه مدل: نه ردیفی دارد، نه ستونی، نه `exists`. پرسیدنش از او
        // روی هر درخواستِ پنل خطای مرگبار است، و همین‌طور هم پیش آمد:
        // ‏`! $token->exists` شکلِ محتاطانهٔ این بررسی به نظر می‌رسید و
        // همانی بود که میزِ کار را از کار انداخت.
        if (! $token instanceof Model) {
            return $next($request);
        }

        // قبل از هر نوشتنی جمع می‌شود، تا یک ذخیره هر چه عوض شده را
        // با خود ببرد، نه اینکه دو ذخیره روی یک ردیف مسابقه بدهند.
        $changed = [];

        $version = $this->clean($request->header('X-App-Version'));

        if ($version !== null && $version !== $token->app_version) {
            $changed['app_version'] = $version;
        }

        // «Android 7.0». به همان شکل و به همان دلیل خوانده می‌شود:
        // گوشی‌ای که نسخهٔ تازه رویش نصب نمی‌شود تقریباً همیشه گوشیِ
        // زیادی قدیمی است، و هیچ صفحه‌ای این را نمی‌گفت.
        $os = $this->cleanOs($request->header('X-Device-OS'));

        if ($os !== null && $os !== $token->os_version) {
            $changed['os_version'] = $os;
        }

        // عددی که بیلد واقعاً با آن مقایسه می‌شود. کنار نام نگه داشته
        // می‌شود نه اینکه از رویش درآورده شود: درآوردنِ یکی از روی آن
        // یکی، هر جا لازم شود، همان راهی است که این دو به اختلاف
        // می‌رسند.
        $sdk = $this->cleanSdk($request->header('X-Device-SDK'));

        if ($sdk !== null && $sdk !== $token->sdk_int) {
            $changed['sdk_int'] = $sdk;
        }

        if ($changed !== []) {
            $token->forceFill($changed)->save();
        }

        return $next($request);
    }

    /**
     * چیزی را که شکلِ یک نسخه دارد نگه می‌دارد و بقیه را دور می‌ریزد.
     *
     * این رشته در ستونی نوشته می‌شود که بعداً خوانده و به صاحب نشان
     * داده می‌شود، و از شبکه می‌آید. هر چه رقم و نقطه و خط‌تیره و حرف
     * نباشد، شمارهٔ نسخه نیست.
     */
    private function clean(?string $raw): ?string
    {
        $value = trim($raw ?? '');

        if ($value === '' || ! preg_match('/^[0-9A-Za-z.+-]{1,'.self::MAX.'}$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * نام یک سیستم‌عامل، محدود به آنچه نام سیستم‌عامل می‌تواند باشد.
     *
     * بازتر از شمارهٔ نسخه — «Android 7.0» فاصله و یک کلمه دارد — و باز
     * هم هیچ‌چیزی جز حرف و رقم و نقطه و فاصله و خط‌تیره. در ستونی
     * نوشته می‌شود که صاحب می‌خواندش، و از شبکه می‌آید.
     */
    private function cleanOs(?string $raw): ?string
    {
        $value = trim($raw ?? '');

        if ($value === '' || ! preg_match('/^[0-9A-Za-z. -]{1,30}$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * سطحِ API، یا null.
     *
     * از هر دو طرف محدود: زیر ۱ سطح نیست، و ستون یک عدد کوچکِ بدون
     * علامت است، پس عددی بیرون از دامنه‌اش نوشتنی است که شکست می‌خورد،
     * نه فیلدی که خالی می‌ماند.
     */
    private function cleanSdk(?string $raw): ?int
    {
        $value = trim($raw ?? '');

        if (! preg_match('/^[0-9]{1,3}$/', $value)) {
            return null;
        }

        $level = (int) $value;

        return $level >= 1 ? $level : null;
    }
}
