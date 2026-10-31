<?php

namespace App\Console\Commands;

use App\Console\Concerns\ForEachBakery;
use App\Models\SellerWarningRule;
use App\Support\SellerWarningRules;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FileSellerRuleWarnings extends Command
{
    use ForEachBakery;

    protected $signature = 'sellers:rule-warnings
        {--day= : the day to check (Y-m-d); yesterday by default}
        {--dry-run : list what would be filed, write nothing}';

    protected $description = 'قوانین فعال فروشنده را روی دیروز می‌سنجد و اخطار در انتظار تأیید مدیر ثبت می‌کند';

    public function handle(): int
    {
        $day = $this->option('day') ? Carbon::parse($this->option('day')) : now()->subDay();

        $this->forEachBakery(function ($bakery) use ($day) {
            SellerWarningRule::ensureDefaults();
            if ($this->option('dry-run')) {
                foreach (SellerWarningRules::hits($day) as $h) {
                    $this->line($bakery->name.' | '.$h['seller']->name.' | '.$h['rule']->name.' | '.$h['detail']);
                }

                return;
            }
            $this->line($bakery->name.': '.SellerWarningRules::file($day));
        });

        return self::SUCCESS;
    }
}
