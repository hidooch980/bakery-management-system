<?php

namespace App\Filament\Widgets;

use App\Models\DieselAllocation;
use App\Models\Sale;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\SellerSettlement;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The four figures the owner opens the panel to find.
 *
 * Everything here was already in the system, but each lived on a different
 * page: takings on the sales list, seller debt in a table three clicks
 * away, the diesel quota on its own screen, the month's profit inside a
 * report with a date range to fill in. Answering "how are we doing" meant
 * visiting four places and holding the numbers in your head.
 */
class MoneyAtAGlance extends BaseWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        [$monthFrom, $monthTo] = Jalali::currentMonthRange();

        return [
            $this->takings(),
            $this->sellerDebt(),
            $this->diesel(),
            $this->monthProfit($monthFrom, $monthTo),
        ];
    }

    private function takings(): Stat
    {
        $today = (float) Sale::whereDate('created_at', now())->sum('amount');
        $yesterday = (float) Sale::whereDate('created_at', now()->subDay())->sum('amount');

        $change = $today - $yesterday;

        return Stat::make('فروش امروز', Money::format($today))
            ->description(match (true) {
                abs($yesterday) < 0.01 => 'دیروز فروشی ثبت نشده بود',
                $change >= 0 => Money::format($change).' بیشتر از دیروز',
                default => Money::format(abs($change)).' کمتر از دیروز',
            })
            ->descriptionIcon($change >= 0
                ? 'heroicon-m-arrow-trending-up'
                : 'heroicon-m-arrow-trending-down')
            ->color($change >= 0 ? 'success' : 'warning');
    }

    private function sellerDebt(): Stat
    {
        $sellers = User::query()->ofCurrentBakery()
            ->whereHas('sales', fn ($q) => $q->sellerAccountOutstanding())
            ->get();

        $total = 0.0;
        $oldestDays = 0;

        foreach ($sellers as $seller) {
            $total += SellerSettlement::outstandingFor($seller)['total'];

            $oldest = Sale::query()
                ->where('user_id', $seller->id)
                ->sellerAccountOutstanding()
                ->oldest('created_at')
                ->value('created_at');

            if ($oldest) {
                $oldestDays = max($oldestDays, (int) $oldest->diffInDays(now()));
            }
        }

        return Stat::make('نزد فروشنده‌ها', Money::format($total))
            ->description($sellers->isEmpty()
                ? 'همه حساب‌ها تسویه است'
                : $sellers->count().' نفر — قدیمی‌ترین '.$oldestDays.' روز')
            ->descriptionIcon('heroicon-m-user-group')
            // A week is the point where a balance stops being the shop's
            // ordinary rhythm and starts being money nobody is chasing.
            ->color(match (true) {
                $total <= 0 => 'success',
                $oldestDays >= 7 => 'danger',
                default => 'warning',
            });
    }

    private function diesel(): Stat
    {
        $quota = DieselAllocation::current();

        if (! $quota) {
            return Stat::make('گازوئیل', '—')
                ->description('سهمیه این ماه ثبت نشده')
                ->descriptionIcon('heroicon-m-fire')
                ->color('gray');
        }

        $remaining = $quota->remaining_litres;

        return Stat::make('گازوئیل مانده', number_format($remaining, 0).' لیتر')
            ->description($quota->used_percent.'٪ سهمیه ماه مصرف شده')
            ->descriptionIcon('heroicon-m-fire')
            ->color(match (true) {
                $quota->is_overdrawn => 'danger',
                $quota->used_percent >= 80 => 'warning',
                default => 'success',
            });
    }

    private function monthProfit($from, $to): Stat
    {
        $income = Ledger::totalIncome($from, $to);
        $cost = Ledger::costOfGoodsSold($from, $to);
        $expenses = Ledger::totalExpenses($from, $to);
        $net = $income - $cost - $expenses;

        return Stat::make('سود خالص ماه', Money::format($net))
            // The margin, because the figure alone says nothing about
            // whether a big month was also a good one.
            ->description($income > 0
                ? 'حاشیه سود '.round($net / $income * 100, 1).'٪ از '.Money::format($income)
                : 'هنوز درآمدی برای این ماه ثبت نشده')
            ->descriptionIcon($net >= 0
                ? 'heroicon-m-arrow-trending-up'
                : 'heroicon-m-arrow-trending-down')
            ->color($net >= 0 ? 'success' : 'danger');
    }
}
