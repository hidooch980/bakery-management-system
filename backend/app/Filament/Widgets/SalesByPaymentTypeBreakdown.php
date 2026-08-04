<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SaleResource;
use App\Models\Sale;
use App\Support\CurrentBakery;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Each payment type with the bread it moved and the money it brought in,
 * then the day's totals and whether the money matches the bread.
 */
class SalesByPaymentTypeBreakdown extends BaseWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $sales = Sale::whereDate('created_at', now()->toDateString())->get();
        $byType = $sales->groupBy('payment_type');

        $stats = [];

        foreach (SaleResource::PAYMENT_LABELS as $key => $label) {
            $group = $byType->get($key);
            $breadCount = (int) ($group?->sum('bread_count') ?? 0);
            $amount = (float) ($group?->sum('amount') ?? 0);

            $stats[] = Stat::make($label, Money::format($amount))
                ->description(number_format($breadCount).' نان'
                    .'   •   '.number_format($group?->count() ?? 0).' فقره')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color($breadCount > 0 ? 'success' : 'gray');
        }

        $totalBread = (int) $sales->sum('bread_count');
        $totalAmount = (float) $sales->sum('amount');

        // What the bread sold should have brought in at the shop's own
        // price. A gap means a sale was recorded with an amount that does
        // not match its bread count — worth seeing rather than hiding.
        $breadPrice = (float) (CurrentBakery::get()->bread_price ?? 0);
        $expected = $totalBread * $breadPrice;
        $difference = round($totalAmount - $expected, 2);

        $stats[] = Stat::make('جمع کل فروش', Money::format($totalAmount))
            ->description(number_format($totalBread).' نان در '
                .number_format($sales->count()).' فقره')
            ->descriptionIcon('heroicon-m-banknotes')
            ->color('primary');

        $stats[] = Stat::make('اختلاف', $breadPrice <= 0
            ? '—'
            : ($difference > 0 ? '+' : '').Money::format($difference))
            ->description($breadPrice <= 0
                ? 'قیمت نان در تنظیمات نانوایی ثبت نشده است'
                : 'نسبت به '.Money::format($expected).' مورد انتظار')
            ->descriptionIcon($difference == 0
                ? 'heroicon-m-check-circle'
                : 'heroicon-m-exclamation-triangle')
            ->color(match (true) {
                $breadPrice <= 0 => 'gray',
                $difference == 0 => 'success',
                default => 'warning',
            });

        return $stats;
    }
}
