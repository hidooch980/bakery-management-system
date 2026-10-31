<?php

namespace App\Filament\Widgets;

use App\Support\Jalali;
use App\Support\Money;
use App\Support\ShopChecks;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/** «سود روزانه»: sales − flour baked − a day's wages − other spending, the last week. */
class DailyProfitChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '260px';

    private ?array $rows = null;

    private function rows(): array
    {
        return $this->rows ??= ShopChecks::dailyProfit(now(), 7);
    }

    public function getHeading(): string
    {
        return 'سود روزانه (۷ روز اخیر) — '.Money::label();
    }

    public function getDescription(): ?string
    {
        $today = last($this->rows());

        return 'امروز: فروش '.Money::format($today['income'])
            .' − آرد '.Money::format($today['flour'])
            .' − حقوق '.Money::format($today['wages'])
            .' − هزینه‌ها '.Money::format($today['expenses'])
            .' = سود '.Money::format($today['profit']);
    }

    protected function getData(): array
    {
        $rows = collect($this->rows());

        return [
            'datasets' => [
                [
                    'label' => 'سود',
                    'data' => $rows->map(fn ($r) => round(Money::convert($r['profit']), 0))->all(),
                    'backgroundColor' => $rows->map(fn ($r) => $r['profit'] >= 0 ? 'rgba(46,158,107,0.7)' : 'rgba(209,73,91,0.7)')->all(),
                ],
                [
                    'label' => 'فروش',
                    'type' => 'line',
                    'data' => $rows->map(fn ($r) => round(Money::convert($r['income']), 0))->all(),
                    'borderColor' => '#C08A2E',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $rows->map(fn ($r) => Jalali::format(Carbon::parse($r['date']), 'm/d'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
