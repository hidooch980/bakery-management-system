<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ارسال پیامک با تنظیمات سرویس نانوایی.
 *
 * مسیر پیش‌فرض فقط در لاگ می‌نویسد تا بازیابی رمز پیش از خرید حساب هم
 * قابل استفاده باشد. خطای سرویس در بازیابی رمز بیرون داده نمی‌شود، چون
 * نباید از پاسخ فهمید شماره در سیستم وجود دارد یا نه. گزارش حساب از
 * مسیر جداگانه ارسال می‌شود و ثبت در لاگ را ارسال موفق نمی‌شمارد.
 */
class Sms
{
    /**
     * پیام‌های آزمون به‌جای ارسال واقعی اینجا نگه داشته می‌شوند.
     * مقدار null یعنی ارسال واقعی. استخراج کد با حدس‌زدن همهٔ کدهای
     * شش‌رقمی و مقایسهٔ هش، آزمون را ده‌ها دقیقه متوقف می‌کرد.
     */
    private static ?array $captured = null;

    public static function fake(): void
    {
        self::$captured = [];
    }

    /** @return array<int, array{phone: string, message: string}> */
    public static function sent(): array
    {
        return self::$captured ?? [];
    }

    public static function stopFaking(): void
    {
        self::$captured = null;
    }

    /** پیام گزارش فقط از مسیر ارسال عادی می‌رود؛ ثبت در لاگ موفقیت نیست. */
    public static function sendReport(string $phone, string $message): bool
    {
        $phone = self::normalise($phone);

        if ($phone === null || trim($message) === '') {
            return false;
        }

        if (self::$captured !== null) {
            self::$captured[] = ['phone' => $phone, 'message' => $message];

            return true;
        }

        // تنظیمات کد بازیابی تغییر نمی‌کند؛ گزارش به قالب رمز یک‌بارمصرف فرستاده نمی‌شود.
        return match (config('sms.driver')) {
            'smsir', 'smsir_bulk' => self::viaSmsIrBulk($phone, $message),
            default => false,
        };
    }

    /**
     * اگر پیام تحویل مسیر ارسال شد درست برمی‌گرداند؛ استثنا بیرون نمی‌رود.
     */
    public static function send(string $phone, string $message, ?string $code = null): bool
    {
        $phone = self::normalise($phone);

        if ($phone === null) {
            return false;
        }

        if (self::$captured !== null) {
            self::$captured[] = ['phone' => $phone, 'message' => $message];

            return true;
        }

        return match (config('sms.driver')) {
            'kavenegar' => self::viaKavenegar($phone, $message),
            'ghasedak' => self::viaGhasedak($phone, $message),
            'smsir' => self::viaSmsIr($phone, $code),
            'smsir_bulk' => self::viaSmsIrBulk($phone, $message),
            default => self::viaLog($phone, $message),
        };
    }

    /**
     * شمارهٔ همراه ایران در قالب یکنواخت سرویس‌دهنده.
     * پیش‌شماره‌های ۰، ۹۸+ و ۰۰۹۸ و فاصله و خط تیره یکسان‌سازی می‌شوند.
     */
    public static function normalise(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', self::latinDigits($phone)) ?? '';

        $digits = match (true) {
            str_starts_with($digits, '0098') => '0'.substr($digits, 4),
            str_starts_with($digits, '98') && strlen($digits) === 12 => '0'.substr($digits, 2),
            str_starts_with($digits, '9') && strlen($digits) === 10 => '0'.$digits,
            default => $digits,
        };

        return preg_match('/^09\d{9}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * رقم‌های فارسی و عربی برای خواندن شماره به لاتین تبدیل می‌شوند.
     */
    public static function latinDigits(string $value): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

        return str_replace(
            [...$persian, ...$arabic],
            [...range(0, 9), ...range(0, 9)],
            $value,
        );
    }

    /**
     * بدون حساب سرویس‌دهنده، پیام فقط در لاگ ثبت می‌شود.
     * سطح هشدار کمک می‌کند مسئول نانوایی کد بازیابی را سریع پیدا کند؛
     * این مسیر به معنی ارسال واقعی به گوشی نیست.
     */
    private static function viaLog(string $phone, string $message): bool
    {
        Log::warning('پیامک (بدون سرویس‌دهنده — فقط ثبت شد)', [
            'to' => $phone,
            'message' => $message,
        ]);

        return true;
    }

    private static function viaKavenegar(string $phone, string $message): bool
    {
        $key = config('sms.kavenegar.key');

        if (blank($key)) {
            return self::viaLog($phone, $message);
        }

        try {
            $response = Http::timeout(10)->get(
                config('sms.kavenegar.url')."/{$key}/sms/send.json",
                array_filter([
                    'receptor' => $phone,
                    'message' => $message,
                    'sender' => config('sms.from'),
                ]),
            );

            return $response->successful();
        } catch (\Throwable $e) {
            // بازیابی رمز نباید تفاوت خطای ارسال و شماره ثبت‌نشده را آشکار کند.
            Log::error('ارسال پیامک ناموفق بود', ['to' => $phone, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private static function viaGhasedak(string $phone, string $message): bool
    {
        $key = config('sms.ghasedak.key');

        if (blank($key)) {
            return self::viaLog($phone, $message);
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['apikey' => $key])
                ->asForm()
                ->post(config('sms.ghasedak.url').'/sms/send/simple', array_filter([
                    'receptor' => $phone,
                    'message' => $message,
                    'linenumber' => config('sms.from'),
                ]));

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('ارسال پیامک ناموفق بود', ['to' => $phone, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** ارسال کد با قالب تأییدشدهٔ پنل اس‌ام‌اس‌آی‌آر؛ کد در گزارش ذخیره نمی‌شود. */
    private static function viaSmsIr(string $phone, ?string $code): bool
    {
        $key = config('sms.smsir.key');
        $template = (int) config('sms.smsir.template_id');
        $parameter = trim((string) config('sms.smsir.parameter', 'CODE'));

        if (blank($key) || $template <= 0 || $parameter === ''
            || $code === null || ! preg_match('/^\d{6}$/', $code)) {
            Log::warning('تنظیمات ارسال کد با اس‌ام‌اس‌آی‌آر کامل نیست.');

            return false;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-API-KEY' => $key])
                ->acceptJson()
                ->post('https://api.sms.ir/v1/send/verify', [
                    'Mobile' => $phone,
                    'TemplateId' => $template,
                    'Parameters' => [['Name' => $parameter, 'Value' => $code]],
                ]);

            // پاسخ HTTP موفق به‌تنهایی به معنی پذیرفته‌شدن پیامک نیست.
            if ($response->successful() && (int) $response->json('status') === 1) {
                return true;
            }

            Log::warning('سرویس اس‌ام‌اس‌آی‌آر درخواست را نپذیرفت.', [
                'http_status' => $response->status(),
                'provider_status' => $response->json('status'),
            ]);
        } catch (\Throwable $e) {
            // متن استثنا ممکن است کلید یا کد را داشته باشد؛ فقط نوعش ثبت می‌شود.
            Log::error('ارتباط با اس‌ام‌اس‌آی‌آر ناموفق بود.', ['type' => $e::class]);
        }

        return false;
    }

    /** ارسال پیامک عادی از خط حساب، بدون نیاز به قالب. */
    private static function viaSmsIrBulk(string $phone, string $message): bool
    {
        $key = config('sms.smsir.key');
        $line = config('sms.from');
        if (blank($key) || blank($line) || trim($message) === '') {
            Log::warning('تنظیمات ارسال عادی پیامک کامل نیست.');

            return false;
        }
        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-API-KEY' => $key])
                ->acceptJson()
                ->post('https://api.sms.ir/v1/send/bulk', [
                    'lineNumber' => $line,
                    'messageText' => $message,
                    'mobiles' => [$phone],
                    'sendDateTime' => null,
                ]);
            if ($response->successful() && (int) $response->json('status') === 1) {
                return true;
            }
            Log::warning('ارسال عادی پیامک پذیرفته نشد.', [
                'http_status' => $response->status(),
                'provider_status' => $response->json('status'),
            ]);
        } catch (\Throwable $e) {
            Log::error('ارتباط ارسال عادی پیامک ناموفق بود.', ['type' => $e::class]);
        }

        return false;
    }
}
