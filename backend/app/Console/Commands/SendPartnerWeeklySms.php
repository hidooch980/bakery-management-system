<?php

namespace App\Console\Commands;

use App\Console\Concerns\ForEachBakery;
use App\Models\Customer;
use App\Models\NotificationSetting;
use App\Support\Notify;
use App\Support\PartnerFlourReport;
use Illuminate\Console\Command;

class SendPartnerWeeklySms extends Command
{
    use ForEachBakery;

    protected $signature = 'partners:weekly-sms';

    protected $description = 'خلاصهٔ هفتگی حساب آرد هر همکار را از بله یا پیامک می‌فرستد (اگر در تنظیمات روشن باشد)';

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
                $log = Notify::person('partner_weekly', $partner, $message, true);
                $this->line($bakery->name.' / '.$partner->name.': '.$log->channel.' '.$log->status);
            }
        });

        return self::SUCCESS;
    }
}
