<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\BakeryStatsOverview;
use App\Filament\Widgets\FlourQuotaOverview;
use App\Filament\Widgets\InventoryOverview;
use App\Filament\Widgets\MoneyAtAGlance;
use App\Filament\Widgets\ProductionTrendChart;
use App\Filament\Widgets\SystemVersusOvenOverview;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Only the "check every morning" widgets live here. Every other widget
 * (financial charts, attendance, work-start lateness, outstanding debts,
 * bank balances) moved to the list page of the resource it's actually
 * about — a dozen full-width panels stacked on one screen made the whole
 * admin panel feel cluttered and slow to scan.
 */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            // The four figures the owner opens the panel to find, before
            // the production detail underneath them.
            MoneyAtAGlance::class,
            BakeryStatsOverview::class,
            ProductionTrendChart::class,
            InventoryOverview::class,
            FlourQuotaOverview::class,
            // Beside the quota on purpose: the quota follows what the
            // national system saw, and this is the only place that says
            // how much of the month's baking it did not.
            SystemVersusOvenOverview::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
