<?php

namespace App\Console\Commands;

use App\Models\Bakery;
use App\Models\Sale;
use App\Models\SettlementRequest;
use App\Models\User;
use App\Support\CurrentBakery;
use App\Support\Money;
use App\Support\SellerLedger;
use Illuminate\Console\Command;

/**
 * Brings each seller's account back to what the ledger says.
 *
 * Payments used to be spent on customer credit (schools, نسیه) that a
 * settlement never closes, so the money was taken and the debt stayed. The
 * cause is fixed; this puts the old accounts right and writes the
 * handovers that never reached the history page.
 *
 * Run with --dry-run first: it prints before and after for every seller and
 * changes nothing.
 */
class RepairSellerLedger extends Command
{
    protected $signature = 'sellers:repair-ledger {--dry-run : فقط نشان بده، چیزی تغییر نکند} {--seller= : فقط این فروشنده (شناسه)}';

    protected $description = 'حساب فروشنده‌ها را با دفتر بدهی/تسویه یکی می‌کند';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('seller');
        $changed = 0;

        foreach (Bakery::query()->orderBy('id')->get() as $bakery) {
            CurrentBakery::for($bakery->id, function () use ($bakery, $dry, $only, &$changed) {
                $ids = Sale::query()->select('user_id')->distinct()->pluck('user_id')
                    ->merge(SettlementRequest::query()->select('user_id')->distinct()->pluck('user_id'))
                    ->unique()->filter();

                $sellers = User::query()->ofCurrentBakery()->whereIn('id', $ids)
                    ->when($only, fn ($q) => $q->where('id', $only))
                    ->orderBy('id')->get();

                $rows = [];

                foreach ($sellers as $seller) {
                    $plan = $dry ? SellerLedger::plan($seller) : SellerLedger::repair($seller);

                    if ($plan['fixable'] || $plan['missing_history'] > 0) {
                        $changed++;
                    }

                    $rows[] = [
                        $seller->id,
                        $seller->name,
                        Money::format($plan['before']),
                        Money::format($plan['after']),
                        Money::format($plan['difference']),
                        $plan['missing_history'],
                        $plan['fixable'] ? 'اصلاح' : (abs($plan['difference']) < 1 ? 'درست' : 'بررسی دستی'),
                    ];
                }

                if ($rows === []) {
                    return;
                }

                $this->info($bakery->name);
                $this->table(['شناسه', 'فروشنده', 'ماندهٔ فعلی', 'ماندهٔ درست', 'اختلاف', 'تسویهٔ بی‌سابقه', 'وضعیت'], $rows);
            });
        }

        $this->line($dry
            ? 'پیش‌نمایش بود؛ چیزی تغییر نکرد. برای اعمال بدون --dry-run اجرا کنید.'
            : "انجام شد: {$changed} حساب.");

        return self::SUCCESS;
    }
}
