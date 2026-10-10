<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sending one text message.
 *
 * Deliberately small and deliberately not a package. The shop sends
 * exactly one kind of message — a six-digit code to someone who has
 * forgotten their password — and a queue, a notification channel and a
 * driver abstraction for that would be more machinery than the thing it
 * carries.
 *
 * The default driver writes to the log. That is not a stub: it means the
 * whole flow works end to end before the shop has bought an SMS account,
 * and the code can be read out of `storage/logs` in the meantime. On the
 * day an account exists, one line in .env switches it.
 *
 * Nothing here throws on a provider failure. A password reset that returns
 * an error saying «the SMS gateway is down» tells whoever asked that the
 * number they typed is a real user, which is exactly what the flow is
 * built not to say.
 */
class Sms
{
    /**
     * Messages captured instead of sent, while a test is running.
     *
     * Null means «really send». The alternative was a test that recovered
     * the code by hashing every six-digit number until one matched — which
     * is what the first version of these tests did, and it wedged the
     * suite for forty minutes before anyone noticed it was not stuck but
     * simply doing a million bcrypt comparisons.
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

    /** True if the message was handed over. Never throws. */
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
     * An Iranian mobile number in the one shape a provider will accept.
     *
     * The shop's own records hold 09xxxxxxxxx, someone typing on a phone
     * may produce +989xxxxxxxxx or 00989…, and a copied number arrives
     * with spaces and dashes in it. All four are the same number and none
     * of them match each other as strings.
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

    /** Persian and Arabic digits as Latin ones, so a typed number parses. */
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
     * No account yet: the message goes to the log.
     *
     * Written at warning level on purpose. It is not an error, but it is
     * the one line somebody will be searching for at the counter while a
     * baker waits to get back into his phone.
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
            // Swallowed on purpose — see the class note. The caller must
            // not be able to tell a failed send from a phone that is not
            // registered.
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
