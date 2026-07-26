<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\SaleResource;
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
            \App\Filament\Widgets\SalesByPaymentTypeBreakdown::class,
            \App\Filament\Widgets\SalesByPaymentTypeChart::class,
            \App\Filament\Widgets\SellerAccountsTable::class,
            \App\Filament\Widgets\OutstandingDebtsTable::class,
        ];
    }
}
