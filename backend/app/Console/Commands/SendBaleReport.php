<?php

namespace App\Console\Commands;

use App\Console\Concerns\ForEachBakery;
use App\Support\BaleReport;
use App\Support\ManagerAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The nightly / weekly / monthly Bale report, from the repository.
 *
 * Not scheduled here: the server's own cron still runs the old script, and
 * scheduling this as well would send every report twice. Swap the cron to
 * this command once it is deployed.
 */
class SendBaleReport extends Command
{
    use ForEachBakery;

    protected $signature = 'reports:bale
        {kind : nightly, weekly or monthly}
        {--ref= : report as if it were this date (Y-m-d)}
        {--print : print instead of sending}
        {--force : send the monthly report even if today is not the month end}';

    protected $description = 'گزارش شبانه، هفتگی یا ماهانه به بله';

    public function handle(): int
    {
        $kind = (string) $this->argument('kind');
        if (! in_array($kind, ['nightly', 'weekly', 'monthly'], true)) {
            $this->error('kind must be nightly, weekly or monthly');

            return self::INVALID;
        }

        $day = $this->option('ref') ? Carbon::parse($this->option('ref'))->setTime(22, 0) : now();

        if ($kind === 'monthly' && ! $this->option('force') && ! BaleReport::isMonthEnd($day)) {
            $this->line('not the last day of the month');

            return self::SUCCESS;
        }

        $this->forEachBakery(function ($bakery) use ($kind, $day) {
            $text = $kind === 'nightly' ? BaleReport::nightly($day) : BaleReport::period($kind, $day);

            if ($this->option('print')) {
                $this->line($text);

                return;
            }

            $this->line($bakery->name.': '.(ManagerAlert::send($text) ? 'sent' : 'not sent (Bale not set up)'));
        });

        return self::SUCCESS;
    }
}
