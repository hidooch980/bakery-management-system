<?php

namespace App\Filament\Resources\FlourSaleResource\Pages;

use App\Filament\Resources\FlourSaleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFlourSale extends EditRecord
{
    protected static string $resource = FlourSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->label('حذف')];
    }
}
