<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use App\Support\Jalali;
use Filament\Widgets\ChartWidget;

class ExpenseByCategoryChart extends ChartWidget
{
    protected static ?string $heading = 'هزینه‌ها به تفکیک دسته (این ماه)';

    protected static ?int $sort = 5;

    protected function getData(): array
    {
        [$from, $to] = Jalali::currentMonthRange();

        $grouped = Expense::whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy('category');

        $labels = [];
        $values = [];

        foreach (Expense::CATEGORIES as $key => $label) {
            $amount = (float) ($grouped->get($key)?->sum('amount') ?? 0);

            // Keep the doughnut readable by dropping empty categories.
            if ($amount <= 0) {
                continue;
            }

            $labels[] = $label;
            $values[] = round($amount, 2);
        }

        return [
            'datasets' => [[
                'label' => 'مبلغ',
                'data' => $values,
                'backgroundColor' => [
                    '#E8952D', '#3B82C4', '#2E9E6B', '#8B5CF6',
                    '#D1495B', '#94A3B8', '#0EA5E9',
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
