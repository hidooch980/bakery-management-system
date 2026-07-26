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

                return Stat::make($item->name, number_format($item->balance, 1).' '.$item->unit)
                    ->description($bags !== null
                        ? number_format($bags, 1).' کیسه'
                        : ($item->is_low ? 'کمتر از حد هشدار' : 'موجودی کافی'))
                    ->descriptionIcon($item->is_low
                        ? 'heroicon-m-exclamation-triangle'
                        : 'heroicon-m-check-circle')
                    ->color($item->is_low ? 'danger' : 'success');
            })
            ->all();
    }
}
