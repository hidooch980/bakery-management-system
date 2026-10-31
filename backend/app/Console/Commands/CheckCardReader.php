<?php

namespace App\Console\Commands;

use App\Console\Concerns\ForEachBakery;
use App\Models\NotificationSetting;
use App\Support\AppCalendar;
use App\Support\ManagerAlert;
use App\Support\Money;
use App\Support\ShopChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CheckCardReader extends Command
{
    use ForEachBakery;

    protected $signature = 'bank:card-reader-check {--date= : روز فروش (Y-m-d)؛ پیش‌فرض دیروز}';

    protected $description = 'فروش کارتخوان ثبت‌شده را با واریز کارتخوان (پیامک بانک روز بعد) مقایسه می‌کند';

    public function handle(): int
    {
        $day = $this->option('date') ? Carbon::parse($this->option('date')) : now()->subDay();

        $this->forEachBakery(function ($bakery) use ($day) {
            $settings = NotificationSetting::current();
            if (! $settings->card_reader_check_enabled) {
                return;
            }
            $r = ShopChecks::cardReader($day);
            $this->line($bakery->name.': '.json_encode($r));
            if ($r['sales'] < 0.01 && $r['deposits'] < 0.01) {
                return;
            }
            if (abs($r['difference']) <= (float) $settings->card_reader_tolerance) {
                return;
            }
            ManagerAlert::send(implode("\n", [
                'مغایرت کارتخوان — '.$bakery->name,
                'روز فروش: '.AppCalendar::date($day),
                'فروش کارتخوان ثبت‌شده: '.Money::format($r['sales']),
                'واریز کارتخوان (پیامک بانک روز بعد): '.Money::format($r['deposits']),
                'اختلاف: '.Money::format(abs($r['difference'])).($r['difference'] < 0 ? ' کمتر واریز شده' : ' بیشتر واریز شده'),
                $r['sms_count'] === 0 ? 'هیچ پیامک واریز کارتخوانی برای روز بعد ثبت نشده است.' : '',
            ]));
        });

        return self::SUCCESS;
    }
}
