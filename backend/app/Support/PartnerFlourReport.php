<?php

namespace App\Support;

use App\Models\BaleSetting;
use App\Models\Customer;
use App\Models\SmsLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** گزارش آرد امانی با همان اعداد پرونده؛ شماره همیشه از اطلاعات همکار خوانده می‌شود. */
class PartnerFlourReport
{
    public static function preview(Customer $partner, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $partner = Customer::query()->partners()->findOrFail($partner->getKey());

        if ($from && $to && $from->gt($to)) {
            throw ValidationException::withMessages(['message' => 'تاریخ شروع نباید بعد از پایان باشد.']);
        }

        $statement = PartnerStatement::for($partner, $from, $to);
        $phone = Sms::normalise($partner->phone);
        $range = ($from ? AppCalendar::date($from) : 'ابتدا')
            .' تا '.($to ? AppCalendar::date($to) : 'امروز');
        $message = implode("\n", [
            'گزارش آرد امانی — '.CurrentBakery::get()?->name,
            'همکار: '.$partner->name,
            'تاریخ گزارش: '.AppCalendar::date(now()),
            'بازه: '.$range,
            'مانده اول بازه: '.self::balanceText($statement->opening),
            'تحویلی به شما: '.PartnerStatement::bags($statement->total(PartnerStatement::LENT)).' کیسه',
            'دریافتی از شما: '.PartnerStatement::bags($statement->total(PartnerStatement::BORROWED)).' کیسه',
            'برگشت از شما: '.PartnerStatement::bags($statement->total(PartnerStatement::RETURNED_TO_US)).' کیسه',
            'برگشت به شما: '.PartnerStatement::bags($statement->total(PartnerStatement::RETURNED_BY_US)).' کیسه',
            'مانده پایان بازه: '.self::balanceText($statement->closing),
            'مانده فعلی: '.self::balanceText($statement->current),
        ]);

        $viaBale = (bool) BaleSetting::query()->first()?->safir_enabled;

        return [
            'channel' => $viaBale ? 'بله؛ پیامک فقط در صورت عدم عضویت' : 'پیامک',
            'phone' => $phone ?? '',
            'message' => $message,
            'fingerprint' => hash('sha256', ($phone ?? '')."\n".$message."\n".($viaBale ? 'bale' : 'sms')),
        ];
    }

    private static function balanceText(float $balance): string
    {
        $bakery = CurrentBakery::get()?->name;
        $bags = PartnerStatement::bags($balance);

        return match (true) {
            $balance > 0.001 => 'شما '.$bags.' کیسه به '.$bakery.' بدهکارید.',
            $balance < -0.001 => $bakery.' '.$bags.' کیسه به شما بدهکار است.',
            default => 'حساب آرد تسویه است.',
        };
    }

    public static function send(Customer $partner, string $fingerprint, ?Carbon $from = null, ?Carbon $to = null): string
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->hasRole('admin'), 403);

        $key = 'partner-flour-report:'.CurrentBakery::id().':'.$partner->getKey();
        $sent = Cache::lock($key.':lock', 30)->get(function () use ($partner, $fingerprint, $from, $to, $key) {
            $preview = self::preview($partner, $from, $to);

            if (! hash_equals($preview['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['message' => 'حساب یا شماره همکار تغییر کرده؛ پیش‌نمایش را دوباره باز کنید.']);
            }

            if ($preview['phone'] === '') {
                throw ValidationException::withMessages(['phone' => 'شماره همراه معتبر همکار را در پرونده او ثبت کنید.']);
            }

            if (RateLimiter::tooManyAttempts($key, 1)) {
                throw ValidationException::withMessages(['message' => 'گزارش همین همکار به‌تازگی ارسال شده؛ یک دقیقه صبر کنید.']);
            }

            $setting = BaleSetting::query()->first();
            if ($setting?->safir_enabled) {
                $requestId = Cache::remember($key.':request:'.$fingerprint, 86400, fn () => (string) Str::uuid());
                $result = BaleSafir::send($setting, $preview['phone'], $preview['message'], $requestId);
                if ($result === 'sent') {
                    RateLimiter::hit($key, 60);
                    self::log($partner, $preview, 'bale', 'sent');

                    return 'بله';
                }
                if ($result !== 'not_member') {
                    throw ValidationException::withMessages(['message' => 'ارسال بله تأیید نشد. تنظیمات، اعتبار و وضعیت سفیر را بررسی و سپس تلاش کنید. برای جلوگیری از ارسال دوگانه، پیامک جایگزین فرستاده نشد.']);
                }
            }

            if (! Sms::sendReport($preview['phone'], $preview['message'])) {
                self::log($partner, $preview, 'sms', 'failed', 'سرویس پیامک پیام را نپذیرفت.');
                throw ValidationException::withMessages(['message' => 'ارسال پذیرفته نشد. سرویس ارسال عادی اس‌ام‌اس‌آی‌آر، خط فرستنده، اعتبار و اتصال را بررسی کنید.']);
            }

            RateLimiter::hit($key, 60);
            self::log($partner, $preview, 'sms', 'sent');

            return 'پیامک';
        });

        if (! is_string($sent)) {
            throw ValidationException::withMessages(['message' => 'ارسال گزارش این همکار در حال انجام است.']);
        }

        return $sent;
    }

    private static function log(Customer $partner, array $preview, string $channel, string $status, ?string $error = null): void
    {
        SmsLog::create([
            'kind' => 'partner_manual',
            'recipient_name' => $partner->name,
            'phone_masked' => SmsLog::mask($preview['phone']),
            'message' => $preview['message'],
            'channel' => $channel,
            'status' => $status,
            'error' => $error,
            'sent_by' => auth()->id(),
        ]);
    }

    /**
     * The short weekly SMS: what moved in the last seven days and where the
     * account stands, in bags. Null when nothing is owed either way and
     * nothing moved — a message saying «nothing» is a message too many.
     */
    public static function weekly(Customer $partner): ?string
    {
        $to = now();
        $from = now()->subDays(7)->startOfDay();
        $s = PartnerStatement::for($partner, $from, $to);
        $moved = $s->total(PartnerStatement::LENT) + $s->total(PartnerStatement::BORROWED)
            + $s->total(PartnerStatement::RETURNED_TO_US) + $s->total(PartnerStatement::RETURNED_BY_US);

        if (abs($s->current) < 0.001 && $moved < 0.001) {
            return null;
        }

        return implode("\n", [
            'خلاصهٔ هفتگی آرد — '.CurrentBakery::get()?->name,
            'همکار: '.$partner->name,
            'هفتهٔ گذشته: تحویلی به شما '.PartnerStatement::bags($s->total(PartnerStatement::LENT))
                .' کیسه، دریافتی از شما '.PartnerStatement::bags($s->total(PartnerStatement::BORROWED)).' کیسه',
            'برگشت: از شما '.PartnerStatement::bags($s->total(PartnerStatement::RETURNED_TO_US))
                .' کیسه، به شما '.PartnerStatement::bags($s->total(PartnerStatement::RETURNED_BY_US)).' کیسه',
            'مانده: '.self::balanceText($s->current),
        ]);
    }
}
