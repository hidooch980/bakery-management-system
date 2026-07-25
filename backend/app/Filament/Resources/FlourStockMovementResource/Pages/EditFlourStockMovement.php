<?php

namespace App\Filament\Resources\FlourStockMovementResource\Pages;

use App\Filament\Resources\FlourStockMovementResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFlourStockMovement extends EditRecord
{
    protected static string $resource = FlourStockMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
