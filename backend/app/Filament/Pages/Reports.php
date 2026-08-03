<?php

namespace App\Filament\Pages;

use App\Support\Jalali;
use App\Support\Money;
use App\Support\PeriodBuckets;
use App\Support\ReportSeries;
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
        // Opens on the Jalali month in progress, which is the range the
        // admin asks for far more often than any other.
        [$start, $end] = Jalali::currentMonthRange();

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
            .'   —   '.PeriodBuckets::label($this->granularity());
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
