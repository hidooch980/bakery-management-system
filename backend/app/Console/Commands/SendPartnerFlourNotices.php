<?php

namespace App\Console\Commands;

use App\Console\Concerns\ForEachBakery;
use App\Support\PartnerFlow;
use Illuminate\Console\Command;

/** پیام‌های ورود و خروج آرد امانی که دو دقیقه از آخرین ثبتشان گذشته. */
class SendPartnerFlourNotices extends Command
{
    use ForEachBakery;

    protected $signature = 'partners:flour-notices';

    protected $description = 'پیام ورود و خروج آرد امانی را، برای هر همکار در یک پیام، می‌فرستد (اگر روشن باشد)';

    public function handle(): int
    {
        $this->forEachBakery(function ($bakery) {
            foreach (PartnerFlow::flush() as $customerId => $result) {
                $this->line($bakery->name.' / '.$customerId.': '.$result);
            }
        });

        return self::SUCCESS;
    }
}
