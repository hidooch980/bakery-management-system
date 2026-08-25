<?php

namespace App\Filament\Widgets;

use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Support\DoughFormula;
use App\Support\Jalali;
use Filament\Widgets\ChartWidget;

class ProductionTrendChart extends ChartWidget
{
    protected static ?string $heading = 'روند تولید (۱۴ روز اخیر)';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn ($d) => now()->subDays($d));
        $formula = DoughFormula::fromBakery();

        return [
            'datasets' => [
                [
                    'label' => 'کیسه خمیر',
                    'data' => $days->map(fn ($day) => (float) DoughEntry::whereDate('created_at', $day->toDateString())->sum('bag_count'))->toArray(),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'fill' => true,
                    // 0.35 let the spline overshoot: a day of zero between
                    // two busy days dipped the curve well below zero, which
                    // is not a thing a sack count can do.
                    'tension' => 0.2,
                ],
                [
                    'label' => 'چانه (÷۱۰)',
                    'data' => $days->map(fn ($day) => round((float) ChaneEntry::whereDate('created_at', $day->toDateString())->sum('chane_count') / 10, 1))->toArray(),
                    'borderColor' => '#0ea5e9',
                    'backgroundColor' => 'rgba(14, 165, 233, 0.12)',
                    'fill' => false,
                    'tension' => 0.2,
                ],
                [
                    // The real nanino count, day by day — previously nowhere
                    // on the dashboard, only today's figure existed elsewhere.
                    'label' => 'نانینو (÷۱۰)',
                    // Divided by ten like the chane line beside it. It was
                    // a raw count against two scaled series, so on a busy
                    // day it left the top of the axis and took the chart
                    // with it — the other two lines flattened to nothing.
                    'data' => $days->map(fn ($day) => round($formula->naninoCountForWeight(
                        (float) ChaneEntry::whereDate('created_at', $day->toDateString())->sum('nanino_weight_kg')
                    ) / 10, 1))->toArray(),
                    // Was #3B82C4 — a blue a shade away from the line above
                    // it, which no legend could tell apart.
                    'borderColor' => '#22C55E',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.12)',
                    'fill' => false,
                    'tension' => 0.2,
                ],
            ],
            // Jalali day/month labels, so the axis matches the rest of the
            // panel — this used to be raw Gregorian, out of step with every
            // other date-bearing chart.
            'labels' => $days->map(fn ($day) => Jalali::format($day, 'm/d'))->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    // The axis is a production count. Letting Chart.js pick
                    // its own floor meant a negative gridline under a chart
                    // where negative has no meaning.
                    'beginAtZero' => true,
                ],
            ],
        ];
    }
}
