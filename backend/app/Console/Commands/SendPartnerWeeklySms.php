<?php

namespace App\Console\Commands;

use App\Console\Concerns\ForEachBakery;
use App\Models\Customer;
use App\Models\NotificationSetting;
use App\Support\AutoSms;
use App\Support\PartnerFlourReport;
use Illuminate\Console\Command;

class SendPartnerWeeklySms extends Command
{
    use ForEachBakery;

    protected $signature = 'partners:weekly-sms';

    protected $description = 'خلاصهٔ هفتگی حساب آرد هر همکار را پیامک می‌کند (اگر در تنظیمات روشن باشد)';

    public function handle(): int
    {
        $this->forEachBakery(function ($bakery) {
            if (! NotificationSetting::current()->sms_partner_weekly) {
                return;
            }

            foreach (Customer::query()->partners()->where('is_active', true)->orderBy('id')->get() as $partner) {
                $message = PartnerFlourReport::weekly($partner);
                if ($message === null) {
                    continue;
                }
                $log = AutoSms::send('partner_weekly', $partner->name, $partner->phone, $message, true);
                $this->line($bakery->name.' / '.$partner->name.': '.$log->status);
            }
        });

        return self::SUCCESS;
    }
}
