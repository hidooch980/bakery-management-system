<?php

namespace App\Filament\Pages;

use App\Support\Jalali;
use App\Support\Money;
use App\Support\PeriodBuckets;
use App\Support\ReportSeries;
use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every figure the shop is judged on, over one chosen range.
 *
 * The same numbers were spread across dashboard widgets, the quota table
 * and the sales page, each with its own idea of "this period". Here the
 * range is picked once and money, production and consumption are all read
 * against it, so the three can be compared without doing arithmetic
 * between two screens.
 */
class Reports extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'گزارش‌ها';

    protected static ?string $navigationLabel = 'گزارش جامع';

    protected static ?string $title = 'گزارش جامع';

    protected static ?int $navigationSort = -4;

    protected static string $view = 'filament.pages.reports';

    public ?string $from = null;

    public ?string $to = null;

    public string $granularity = PeriodBuckets::DAY;

    public function mount(): void
    {
        // Opens on the shop's own month — the 5th to the 4th — because
        // that is the cycle the flour quota runs on and therefore the one
        // the shop is actually judged by. The calendar month puts four
        // days at each end into the wrong period.
        [$start, $end] = Jalali::currentQuotaPeriod();

        $this->form->fill([
            'from' => Jalali::date($start),
            'to' => Jalali::date($end),
            'granularity' => PeriodBuckets::DAY,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Grid::make(3)->schema([
                TextInput::make('from')
                    ->label('از تاریخ')
                    ->placeholder('۱۴۰۵/۰۵/۰۱')
                    ->live(onBlur: true),

                TextInput::make('to')
                    ->label('تا تاریخ')
                    ->placeholder('۱۴۰۵/۰۵/۳۱')
                    ->live(onBlur: true),

                Select::make('granularity')
                    ->label('بازه')
                    ->options(PeriodBuckets::GRANULARITIES)
                    ->default(PeriodBuckets::DAY)
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live(),
            ]),
        ]);
    }

    /**
     * The two ranges worth a click rather than four typed digits.
     *
     * Both are offered because they answer different questions: the quota
     * period is what the flour allowance is measured against, and the
     * calendar month is what the partners' split and every conversation
     * outside the shop uses.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('quotaPeriod')
                ->label('دورهٔ سهمیه')
                ->icon('heroicon-o-calendar-days')
                ->color('primary')
                ->tooltip('۵ این ماه تا ۴ ماه بعد — دوره‌ای که سهمیه آرد بر آن حساب می‌شود')
                ->action(fn () => $this->useRange(Jalali::currentQuotaPeriod())),

            Action::make('calendarMonth')
                ->label('ماه شمسی')
                ->icon('heroicon-o-calendar')
                ->color('gray')
                ->tooltip('۱ تا آخر ماه')
                ->action(fn () => $this->useRange(Jalali::currentMonthRange())),

            Action::make('previousQuotaPeriod')
                ->label('دورهٔ قبل')
                ->icon('heroicon-o-arrow-uturn-right')
                ->color('gray')
                ->action(fn () => $this->useRange(
                    // A day before this period opened is inside the last
                    // one, whichever month that lands in.
                    Jalali::quotaPeriodFor(Jalali::currentQuotaPeriod()[0]->copy()->subDay())
                )),
        ];
    }

    /** @param  array{0: Carbon, 1: Carbon}  $range */
    private function useRange(array $range): void
    {
        [$start, $end] = $range;

        $this->form->fill([
            'from' => Jalali::date($start),
            'to' => Jalali::date($end),
            'granularity' => $this->granularity,
        ]);
    }

    public function financialRows(): Collection
    {
        [$from, $to] = $this->range();

        return ReportSeries::financial($from, $to, $this->granularity());
    }

    public function productionRows(): Collection
    {
        [$from, $to] = $this->range();

        return ReportSeries::production($from, $to, $this->granularity());
    }

    public function consumptionRows(): Collection
    {
        [$from, $to] = $this->range();

        return ReportSeries::consumption($from, $to, $this->granularity());
    }

    public function currencyLabel(): string
    {
        return Money::label();
    }

    public function money(float $amount): string
    {
        return Money::format($amount);
    }

    public function rangeLabel(): string
    {
        [$from, $to] = $this->range();

        return Jalali::date($from).' تا '.Jalali::date($to)
            // Saying which kind of window this is, because two dates alone
            // do not tell the owner whether the quota figures below them
            // are the ones the allowance is actually measured against.
            .$this->windowName($from, $to)
            .'   —   '.PeriodBuckets::label($this->granularity());
    }

    private function windowName(Carbon $from, Carbon $to): string
    {
        $sameDay = fn (Carbon $a, Carbon $b) => $a->isSameDay($b);

        [$quotaFrom, $quotaTo] = Jalali::quotaPeriodFor($from);

        if ($sameDay($from, $quotaFrom) && $sameDay($to, $quotaTo)) {
            return '  (دورهٔ سهمیه)';
        }

        [$monthFrom, $monthTo] = Jalali::monthRangeFor($from);

        if ($sameDay($from, $monthFrom) && $sameDay($to, $monthTo)) {
            return '  (ماه شمسی)';
        }

        return '';
    }

    private function granularity(): string
    {
        return PeriodBuckets::normalise($this->granularity);
    }

    /**
     * A half-typed or nonsense date falls back to the current Jalali month
     * rather than throwing while the admin is still typing.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(): array
    {
        [$monthStart, $monthEnd] = Jalali::currentMonthRange();

        $from = Jalali::parseFlexible($this->from)?->startOfDay() ?? $monthStart;
        $to = Jalali::parseFlexible($this->to)?->endOfDay() ?? $monthEnd;

        // Dates entered the wrong way round read as the range between them.
        return $from->lte($to) ? [$from, $to] : [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
    }
}
