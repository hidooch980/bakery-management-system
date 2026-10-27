<?php

namespace App\Support;

use App\Models\BaleSetting;
use App\Models\Customer;
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

                    return 'بله';
                }
                if ($result !== 'not_member') {
                    throw ValidationException::withMessages(['message' => 'ارسال بله تأیید نشد. تنظیمات، اعتبار و وضعیت سفیر را بررسی و سپس تلاش کنید. برای جلوگیری از ارسال دوگانه، پیامک جایگزین فرستاده نشد.']);
                }
            }

            if (! Sms::sendReport($preview['phone'], $preview['message'])) {
                throw ValidationException::withMessages(['message' => 'ارسال پذیرفته نشد. سرویس ارسال عادی اس‌ام‌اس‌آی‌آر، خط فرستنده، اعتبار و اتصال را بررسی کنید.']);
            }

            RateLimiter::hit($key, 60);

            return 'پیامک';
        });

        if (! is_string($sent)) {
            throw ValidationException::withMessages(['message' => 'ارسال گزارش این همکار در حال انجام است.']);
        }

        return $sent;
    }
}
