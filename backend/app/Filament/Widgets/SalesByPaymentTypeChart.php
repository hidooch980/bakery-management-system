<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SaleResource;
use App\Models\Sale;
use Filament\Widgets\ChartWidget;

class SalesByPaymentTypeChart extends ChartWidget
{
    protected static ?string $heading = 'فروش بر اساس نوع پرداخت (۳۰ روز اخیر)';

    protected static ?int $sort = 7;

    protected function getData(): array
    {
        $sales = Sale::where('created_at', '>=', now()->subDays(30))
            ->get()
            ->groupBy('payment_type');

        $labels = [];
        $values = [];

        foreach (SaleResource::PAYMENT_LABELS as $key => $label) {
            $labels[] = $label;
            $values[] = $sales->get($key)?->count() ?? 0;
        }

        return [
            'datasets' => [[
                'label' => 'تعداد فروش',
                'data' => $values,
                'backgroundColor' => [
                    '#10b981', '#0ea5e9', '#f43f5e',
                    '#f59e0b', '#8b5cf6', '#94a3b8',
                ],
            ]],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
