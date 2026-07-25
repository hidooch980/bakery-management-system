<?php

namespace App\Filament\Resources\FlourStockMovementResource\Pages;

use App\Filament\Resources\FlourStockMovementResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFlourStockMovements extends ListRecords
{
    protected static string $resource = FlourStockMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
