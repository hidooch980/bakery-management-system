<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Support\Jalali;
use Filament\Widgets\ChartWidget;

class IncomeExpenseChart extends ChartWidget
{
    protected static ?string $heading = 'درآمد و هزینه (۱۴ روز اخیر)';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn ($d) => now()->subDays($d));

        return [
            'datasets' => [
                [
                    'label' => 'درآمد',
                    'data' => $days->map(fn ($day) => round(
                        (float) Sale::whereDate('created_at', $day->toDateString())->sum('amount'), 2
                    ))->toArray(),
                    'borderColor' => '#2E9E6B',
                    'backgroundColor' => 'rgba(46, 158, 107, 0.15)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
                [
                    'label' => 'هزینه',
                    'data' => $days->map(function ($day) {
                        $date = $day->toDateString();

                        return round(
                            (float) Expense::whereDate('spent_on', $date)->sum('amount')
                            + (float) SalaryPayment::paid()->whereDate('paid_on', $date)->sum('net_amount'),
                            2
                        );
                    })->toArray(),
                    'borderColor' => '#D1495B',
                    'backgroundColor' => 'rgba(209, 73, 91, 0.15)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
            ],
            // Jalali day/month labels, so the axis matches the rest of the panel.
            'labels' => $days->map(fn ($day) => Jalali::format($day, 'm/d'))->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
