<?php

namespace App\Filament\Widgets;

use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use Filament\Widgets\ChartWidget;

class ProductionTrendChart extends ChartWidget
{
    protected static ?string $heading = 'روند تولید (۱۴ روز اخیر)';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn ($d) => now()->subDays($d));

        return [
            'datasets' => [
                [
                    'label' => 'کیسه خمیر',
                    'data' => $days->map(fn ($day) => (float) DoughEntry::whereDate('created_at', $day->toDateString())->sum('bag_count'))->toArray(),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
                [
                    'label' => 'تعداد چانه (÷۱۰)',
                    'data' => $days->map(fn ($day) => round((float) ChaneEntry::whereDate('created_at', $day->toDateString())->sum('chane_count') / 10, 1))->toArray(),
                    'borderColor' => '#0ea5e9',
                    'backgroundColor' => 'rgba(14, 165, 233, 0.15)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
            ],
            'labels' => $days->map(fn ($day) => $day->format('m/d'))->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
