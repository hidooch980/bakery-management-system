<?php

namespace App\Console\Commands;

use App\Console\Concerns\ForEachBakery;
use App\Models\NotificationSetting;
use App\Support\Money;
use App\Support\Notify;
use App\Support\ShopChecks;
use Illuminate\Console\Command;

/** یادآوری مانده بدهی به خود فروشنده‌ای که از سقف گذشته؛ اول بله، وگرنه پیامک. */
class SendSellerDebtReminders extends Command
{
    use ForEachBakery;

    protected $signature = 'sellers:debt-reminders';

    protected $description = 'به فروشنده‌ای که بدهی‌اش از سقف گذشته یادآوری می‌فرستد (اگر در تنظیمات روشن باشد)';

    public function handle(): int
    {
        $this->forEachBakery(function ($bakery) {
            if (! NotificationSetting::current()->sms_seller_debt) {
                return;
            }

            foreach (ShopChecks::sellersOverThreshold() as $row) {
                $log = Notify::person('seller_debt', $row['seller'], self::message($bakery->name, $row['seller']->name, $row['balance']), true);
                $this->line($bakery->name.' / '.$row['seller']->name.': '.$log->channel.' '.$log->status);
            }
        });

        return self::SUCCESS;
    }

    /** مانده پس از لحاظ تسویه‌ها، همان عددی که فروشنده در اپ می‌بیند. */
    public static function message(?string $bakery, ?string $seller, float $balance): string
    {
        return implode("\n", [
            $bakery,
            $seller.' عزیز،',
            'ماندهٔ حساب شما پس از تسویه‌ها '.Money::format($balance).' است.',
            'لطفاً برای تسویه اقدام کنید.',
        ]);
    }
}
