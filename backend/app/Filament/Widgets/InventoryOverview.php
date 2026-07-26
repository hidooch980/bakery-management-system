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
                $weightLabel = number_format($item->balance, 1).' '.$item->unit;

                // Bag count leads, weight sits next to it — not buried in
                // the description under the weight, as it read before.
                $value = $bags !== null
                    ? number_format($bags, 1).' کیسه   —   '.$weightLabel
                    : $weightLabel;

                $statusLabel = $item->is_low ? 'کمتر از حد هشدار' : 'موجودی کافی';

                return Stat::make($item->name, $value)
                    ->description($statusLabel)
                    ->descriptionIcon($item->is_low
                        ? 'heroicon-m-exclamation-triangle'
                        : 'heroicon-m-check-circle')
                    ->color($item->is_low ? 'danger' : 'success');
            })
            ->all();
    }
}
