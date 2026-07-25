<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Support\Jalali;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Income against expenses for the current Jalali month.
 */
class FinancialOverview extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        [$from, $to] = Jalali::currentMonthRange();

        $income = (float) Sale::whereBetween('created_at', [$from, $to])->sum('amount');

        $expenses = (float) Expense::whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->sum('amount');

        $salaries = (float) SalaryPayment::paid()
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->sum('net_amount');

        $totalExpenses = $expenses + $salaries;
        $profit = $income - $totalExpenses;

        $unpaid = (float) SalaryPayment::unpaid()->sum('net_amount');

        return [
            Stat::make('درآمد '.Jalali::monthLabel($from), Money::format($income))
                ->description('مجموع فروش این ماه')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('هزینه‌ها', Money::format($totalExpenses))
                ->description('هزینه '.Money::format($expenses).' + حقوق '.Money::format($salaries))
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->color('danger'),

            Stat::make('سود خالص', Money::format($profit))
                ->description($income > 0
                    ? 'حاشیه سود: '.round($profit / $income * 100, 1).'٪'
                    : 'فروشی ثبت نشده')
                ->descriptionIcon($profit >= 0 ? 'heroicon-m-banknotes' : 'heroicon-m-exclamation-triangle')
                ->color($profit >= 0 ? 'success' : 'danger'),

            Stat::make('حقوق پرداخت‌نشده', Money::format($unpaid))
                ->description(SalaryPayment::unpaid()->count().' مورد در انتظار پرداخت')
                ->descriptionIcon('heroicon-m-clock')
                ->color($unpaid > 0 ? 'warning' : 'gray'),
        ];
    }
}
