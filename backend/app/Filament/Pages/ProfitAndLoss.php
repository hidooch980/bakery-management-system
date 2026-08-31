<?php

namespace App\Filament\Pages;

use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\Ledger;
use App\Support\Money;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Where the money came from and where it went, over one period.
 *
 * Every figure here has been computed by `App\Support\Ledger` since long
 * before this page, and every one of them was already on some screen — the
 * income on the dashboard, the expenses on the expense list, the profit in
 * two different widgets. What did not exist was the statement: the whole
 * thing on one page, in the order that explains it, adding up.
 *
 * The order matters more than the arithmetic. On 2026-08-16 the dashboard
 * and the report disagreed about profit by 164,640,000 Rial because flour
 * was being counted both as cost of goods and as an expense. Two screens
 * each showing half a sum is how that survives; one statement is how it
 * gets caught.
 *
 * The shop's own rule, in the owner's words: «پول اول پرداخت می‌شه» — a
 * cost falls on the day the money leaves. So the headline is income less
 * everything paid out, and the accrual view sits beneath it, labelled,
 * rather than competing with it.
 */
class ProfitAndLoss extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'گزارش‌ها';

    protected static ?string $navigationLabel = 'سود و زیان';

    protected static ?string $title = 'صورت سود و زیان';

    protected static ?int $navigationSort = -2;

    protected static string $view = 'filament.pages.profit-and-loss';

    /**
     * 'quota' — the 5th to the 4th — or 'month', the Jalali calendar one.
     *
     * Nullable because Livewire delivers a cleared select as null by
     * unsetting a non-nullable property, and the very next render then
     * throws PropertyNotFound — it happened to the owner on 1405/06/09.
     * Null simply reads as the default period.
     */
    public ?string $period = 'quota';

    public function mount(): void
    {
        $this->form->fill(['period' => $this->period]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('period')
                ->label('دوره')
                ->options([
                    // The quota period first because it is the one the shop
                    // actually lives by: flour arrives against it and the
                    // month's baking is judged against it.
                    'quota' => 'دورهٔ سهمیه (۵ تا ۴ ماه بعد)',
                    'month' => 'ماه شمسی',
                    'quota_previous' => 'دورهٔ سهمیهٔ قبل',
                    'month_previous' => 'ماه شمسی قبل',
                ])
                ->native(false)
                ->live(),
        ])->statePath('');
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function range(): array
    {
        return match ($this->period) {
            'month' => Jalali::currentMonthRange(),
            'month_previous' => Jalali::monthRangeFor(Jalali::currentMonthRange()[0]->copy()->subDay()),
            'quota_previous' => Jalali::quotaPeriodFor(Jalali::currentQuotaPeriod()[0]->copy()->subDay()),
            default => Jalali::currentQuotaPeriod(),
        };
    }

    public function rangeLabel(): string
    {
        [$from, $to] = $this->range();

        return AppCalendar::date($from).' تا '.AppCalendar::date($to);
    }

    /**
     * The statement, in the order that explains it.
     *
     * Income first because it is the only line the shop can grow; then what
     * was paid out, in the shape the owner thinks in — flour, wages,
     * everything else — and the profit last, because that is the answer
     * rather than the question.
     */
    public function statement(): array
    {
        [$from, $to] = $this->range();

        $income = Ledger::totalIncome($from, $to);
        $flour = Ledger::flourPurchases($from, $to);
        $wages = Ledger::paidSalaries($from, $to);
        $other = Ledger::operatingExpenses($from, $to);
        $expenses = Ledger::totalExpenses($from, $to);

        return [
            'income' => Ledger::incomeBreakdown($from, $to),
            'income_total' => $income,
            'costs' => [
                ['label' => 'خرید آرد', 'amount' => $flour],
                ['label' => 'حقوق پرداخت‌شده', 'amount' => $wages],
                ['label' => 'سایر هزینه‌ها', 'amount' => $other],
            ],
            'expense_total' => $expenses,
            'profit' => Ledger::profit($from, $to),

            // Beside it, never instead of it. Cost of goods counts flour as
            // it is baked rather than as it is bought, which answers a
            // different question and disagrees with the headline in any
            // period where the two do not line up.
            'cogs' => Ledger::costOfGoodsSold($from, $to),
            'gross_profit' => Ledger::grossProfit($from, $to),
        ];
    }

    public function money(float $amount): string
    {
        return Money::format($amount);
    }
}
