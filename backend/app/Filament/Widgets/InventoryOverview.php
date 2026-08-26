<?php

namespace App\Filament\Widgets;

use App\Models\InventoryItem;
use App\Support\Qty;
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
                // Sacks alone where the item has a sack. «کیلو در انبار
                // معنی نداره، فقط کیسه بیاد» — the shop counts its flour
                // in sacks, orders it in sacks and lends it in sacks, and
                // the weight beside the count was a second number saying
                // the same thing in a unit nobody uses at the door.
                //
                // Salt and yeast keep their weight: they arrive in no
                // fixed sack, so the server sends no bag count for them
                // and there is nothing else to say.
                $value = $bags !== null
                    ? Qty::format($bags, 1).' کیسه'
                    : Qty::format($item->balance, 1).' '.$item->unit;

                // Three states, not two. Empty reads as its own thing
                // because «کمتر از حد هشدار» beside 0.0 still sounds like
                // there is some left, and because most items here have no
                // threshold set at all.
                [$statusLabel, $icon, $colour] = match (true) {
                    $item->is_empty => ['موجودی تمام شده', 'heroicon-m-x-circle', 'danger'],
                    $item->is_low => ['کمتر از حد هشدار', 'heroicon-m-exclamation-triangle', 'warning'],
                    default => ['موجودی کافی', 'heroicon-m-check-circle', 'success'],
                };

                return Stat::make($item->name, $value)
                    ->description($statusLabel)
                    ->descriptionIcon($icon)
                    ->color($colour);
            })
            ->all();
    }
}
