<?php

namespace App\Filament\Widgets;

use App\Support\Jalali;
use App\Support\Ledger;
use App\Support\Money;
use Filament\Widgets\ChartWidget;

class IncomeExpenseChart extends ChartWidget
{
    public function getHeading(): string
    {
        // The axis is drawn in whichever unit the shop displays.
        return 'درآمد و هزینه (۱۴ روز اخیر) — '.Money::label();
    }

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn ($d) => now()->subDays($d));

        // Read through Ledger::dailyTotals, the same figures the phone's
        // trend chart shows. This chart counted bread only on one side and
        // left purchase invoices off the other, so flour sold, other
        // income and every delivery were missing from the panel's picture.
        $totals = $days->map(fn ($day) => Ledger::dailyTotals($day));

        return [
            'datasets' => [
                [
                    'label' => 'درآمد',
                    'data' => $totals->map(fn ($t) => round(Money::convert($t[0]), 2))->toArray(),
                    'borderColor' => '#2E9E6B',
                    'backgroundColor' => 'rgba(46, 158, 107, 0.15)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
                [
                    'label' => 'هزینه',
                    'data' => $totals->map(fn ($t) => round(Money::convert($t[1]), 2))->toArray(),
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
