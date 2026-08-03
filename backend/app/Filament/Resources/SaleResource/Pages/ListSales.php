<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\SaleResource;
use App\Filament\Widgets\OutstandingDebtsTable;
use App\Filament\Widgets\SalesByPaymentTypeBreakdown;
use App\Filament\Widgets\SalesByPaymentTypeChart;
use App\Filament\Widgets\SellerAccountsTable;
use App\Filament\Widgets\SettlementRequestsTable;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            SalesByPaymentTypeBreakdown::class,
            SalesByPaymentTypeChart::class,
            SettlementRequestsTable::class,
            SellerAccountsTable::class,
            OutstandingDebtsTable::class,
        ];
    }
}
