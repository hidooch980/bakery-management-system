<?php

namespace App\Filament\Widgets;

use App\Models\DieselAllocation;
use App\Models\Expense;
use App\Models\SalaryPayment;
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
        // The shop's own month, the 5th to the 4th, because that is the
        // cycle the flour quota runs on and the one the report headlines.
        // The two must not answer «how did the month go» differently.
        [$monthFrom, $monthTo] = Jalali::currentQuotaPeriod();

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

        // Two different questions, and the tank is the one that stops the
        // oven: a period can be well inside its quota with nothing left to
        // bake with. The quota goes in the description, where it answers
        // "can I order more" rather than "can I bake today".
        //
        // Only once something has been delivered, though — before the
        // first tanker the tank reads zero because nothing has arrived,
        // and calling that empty would raise an alarm about a period that
        // has not started drawing yet.
        $drawing = $quota->delivered_litres > 0;

        if (! $drawing) {
            return Stat::make('گازوئیل', number_format($quota->remaining_litres, 0).' لیتر')
                ->description('سهمیه دوره — هنوز تحویلی ثبت نشده')
                ->descriptionIcon('heroicon-m-fire')
                ->color('gray');
        }

        return Stat::make('گازوئیل در باک', number_format($quota->in_tank_litres, 0).' لیتر')
            ->description(number_format($quota->remaining_litres, 0)
                .' لیتر از سهمیه دوره مانده')
            ->descriptionIcon('heroicon-m-fire')
            ->color(match (true) {
                $quota->is_tank_empty => 'danger',
                $quota->used_percent >= 80 => 'warning',
                default => 'success',
            });
    }

    private function monthProfit($from, $to): Stat
    {
        $income = Ledger::totalIncome($from, $to);

        // Money in less money out, which is the shop's own rule —
        // «پول اول پرداخت می‌شه»: a sack costs on the day it is paid for,
        // not the day it is kneaded. The same figure the report headlines
        // and the partners' split is paid on.
        //
        // This used to be income minus cost-of-goods minus expenses, which
        // is neither rule: flour sits in both those terms, so the sack was
        // charged twice and the figure read 164,640,000 Rial low.
        $net = Ledger::profit($from, $to);

        $unrecordedWages = $this->wagesNotInTheFigure($from, $to);

        return Stat::make('سود خالص ماه', Money::format($net))
            // The margin, because the figure alone says nothing about
            // whether a big month was also a good one — unless a whole
            // month's wages are missing from it, in which case the margin
            // is not the thing worth saying.
            ->description(match (true) {
                $unrecordedWages > 0 => 'حقوق ثبت نشده — این عدد تا امروز '
                    .Money::format($unrecordedWages).' از واقعیت بیشتر است',
                $income > 0 => 'حاشیه سود '.round($net / $income * 100, 1).'٪ از '.Money::format($income),
                default => 'هنوز درآمدی برای این ماه ثبت نشده',
            })
            ->descriptionIcon($unrecordedWages > 0
                ? 'heroicon-m-exclamation-triangle'
                : ($net >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down'))
            ->color(match (true) {
                $unrecordedWages > 0 => 'warning',
                $net >= 0 => 'success',
                default => 'danger',
            });
    }

    /**
     * Wages the shop has run up so far this period and recorded nowhere.
     *
     * A profit figure that leaves out payroll is not a small bit wrong, it
     * is wrong by the payroll — for this shop a thousand million Rial a
     * period against takings of a few hundred. Saying so on the figure
     * itself is the only place it cannot be missed: the issue centre
     * reports it too, but an owner who has answered that issue, for
     * perfectly good reasons of his own, would still be reading this
     * number as though it meant something.
     *
     * **Pro-rated to the days gone.** The income beside it is the income
     * so far, and setting a whole period's wages against a part period's
     * takings overstates the hole — which is exactly the mistake I made
     * out loud on 2026-08-17, subtracting a full month's payroll from
     * twenty-two days of trading and calling the answer the real profit.
     *
     * Zero once anything is recorded — a payslip or a wage expense. This
     * does not try to work out whether the amount was right, only whether
     * the period is accounted for at all.
     */
    private function wagesNotInTheFigure($from, $to): float
    {
        $recorded = SalaryPayment::paid()
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->exists()
            || Expense::where('category', 'salary')
                ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
                ->exists();

        if ($recorded) {
            return 0.0;
        }

        $monthly = (float) User::query()->ofCurrentBakery()
            ->where('is_active', true)
            ->sum('monthly_salary');

        $days = max(1, (int) $from->diffInDays($to) + 1);
        $gone = min($days, max(0, (int) $from->diffInDays(now()) + 1));

        return round($monthly * $gone / $days, 2);
    }
}
