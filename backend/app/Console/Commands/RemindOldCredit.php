<?php

namespace App\Console\Commands;

use App\Console\Concerns\ForEachBakery;
use App\Models\NotificationSetting;
use App\Support\AppCalendar;
use App\Support\ManagerAlert;
use App\Support\Money;
use App\Support\ShopChecks;
use Illuminate\Console\Command;

class RemindOldCredit extends Command
{
    use ForEachBakery;

    protected $signature = 'customers:credit-reminder';

    protected $description = 'نسیه‌های قدیمی و مشتری‌های بالای سقف اعتبار را در بله به مدیر یادآوری می‌کند';

    public function handle(): int
    {
        $this->forEachBakery(function ($bakery) {
            $settings = NotificationSetting::current();
            if (! $settings->credit_reminder_enabled) {
                return;
            }
            $days = max(1, (int) $settings->credit_reminder_days);
            $rows = ShopChecks::creditToChase($days);
            if ($rows->isEmpty()) {
                return;
            }
            $lines = $rows->map(fn ($r) => '• '.$r['customer']->name.': '.Money::format($r['open'])
                .($r['old'] > 0 ? ' — '.Money::format($r['old']).' قدیمی‌تر از '.$days.' روز (از '.AppCalendar::date($r['oldest']).')' : '')
                .($r['over_limit'] ? ' — بالای سقف '.Money::format((float) $r['customer']->credit_limit) : ''))
                ->all();
            ManagerAlert::send("یادآوری نسیه — {$bakery->name}\n".implode("\n", $lines));
            $this->line($bakery->name.': '.count($lines));
        });

        return self::SUCCESS;
    }
}
