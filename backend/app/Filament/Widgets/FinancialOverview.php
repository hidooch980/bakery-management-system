<?php

namespace App\Filament\Widgets;

use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Support\Jalali;
use App\Support\Ledger;
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

        // Read through the ledger so bread, flour and miscellaneous income
        // are counted here exactly as the reports count them.
        $breakdown = Ledger::incomeBreakdown($from, $to);
        $income = $breakdown['total'];

        $expenses = Ledger::recordedExpenses($from, $to);
        $salaries = Ledger::paidSalaries($from, $to);

        $totalExpenses = $expenses + $salaries;
        $profit = $income - $totalExpenses;

        $unpaid = (float) SalaryPayment::unpaid()->sum('net_amount');

        // Money customers owe us, split by how old the debt is. Flour sold
        // on credit is a debt in exactly the same way bread is.
        $breadDebts = Sale::outstanding()->get()
            ->map(fn (Sale $s) => ['amount' => (float) $s->amount, 'on' => $s->created_at]);
        $flourDebts = \App\Models\FlourSale::outstanding()->get()
            ->map(fn ($s) => ['amount' => (float) $s->amount, 'on' => $s->sold_on]);

        $debts = $breadDebts->concat($flourDebts);
        $debtTotal = (float) $debts->sum('amount');
        $oldDebt = (float) $debts
            ->reject(fn (array $d) => $d['on']->between($from, $to))
            ->sum('amount');

        return [
            Stat::make('درآمد '.Jalali::monthLabel($from), Money::format($income))
                ->description('نان '.$breakdown['bread_formatted']
                    .' • آرد '.$breakdown['flour_formatted']
                    .' • متفرقه '.$breakdown['other_formatted'])
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

            Stat::make('بدهی پرداخت‌نشده', Money::format($debtTotal))
                ->description($oldDebt > 0
                    ? 'از ماه‌های قبل: '.Money::format($oldDebt)
                    : $debts->count().' فقره نسیه و مدارس')
                ->descriptionIcon('heroicon-m-exclamation-circle')
                ->color($oldDebt > 0 ? 'danger' : ($debtTotal > 0 ? 'warning' : 'gray')),
        ];
    }
}
