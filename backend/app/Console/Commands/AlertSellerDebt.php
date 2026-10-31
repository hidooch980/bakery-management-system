<?php

namespace App\Console\Commands;

use App\Console\Concerns\ForEachBakery;
use App\Support\ManagerAlert;
use App\Support\Money;
use App\Support\SellerLedger;
use App\Support\ShopChecks;
use Illuminate\Console\Command;

class AlertSellerDebt extends Command
{
    use ForEachBakery;

    protected $signature = 'sellers:debt-alert';

    protected $description = 'اگر بدهی فروشنده از سقف گذشته باشد یا حسابش با دفتر نخواند به مدیر در بله خبر می‌دهد';

    public function handle(): int
    {
        $this->forEachBakery(function ($bakery) {
            $lines = [];
            foreach (ShopChecks::sellersOverThreshold() as $r) {
                $lines[] = '• '.$r['seller']->name.': '.Money::format($r['balance'])
                    .' (سقف '.Money::format($r['threshold']).')';
                $warning = SellerLedger::warning(SellerLedger::reconcile($r['seller']));
                if ($warning) {
                    $lines[] = '  ⚠️ '.$warning;
                }
            }
            if ($lines === []) {
                return;
            }
            ManagerAlert::send("بدهی فروشنده از سقف گذشته — {$bakery->name}\n".implode("\n", $lines));
            $this->line($bakery->name.': '.count($lines));
        });

        return self::SUCCESS;
    }
}
