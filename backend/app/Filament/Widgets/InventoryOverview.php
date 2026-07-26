<?php

namespace App\Filament\Widgets;

use App\Models\InventoryItem;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InventoryOverview extends BaseWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        return collect(array_keys(InventoryItem::DEFAULTS))
            ->map(function (string $key) {
                $item = InventoryItem::ofKey($key);
                $bags = $item->balance_bags;

                // Salt and dough now have a bag size too, so the count and
                // the low-stock warning must both be shown, not just one.
                $bagsLabel = $bags !== null ? number_format($bags, 1).' کیسه' : null;
                $statusLabel = $item->is_low ? 'کمتر از حد هشدار' : 'موجودی کافی';

                return Stat::make($item->name, number_format($item->balance, 1).' '.$item->unit)
                    ->description($bagsLabel !== null
                        ? ($item->is_low ? "{$bagsLabel} — {$statusLabel}" : $bagsLabel)
                        : $statusLabel)
                    ->descriptionIcon($item->is_low
                        ? 'heroicon-m-exclamation-triangle'
                        : 'heroicon-m-check-circle')
                    ->color($item->is_low ? 'danger' : 'success');
            })
            ->all();
    }
}
