<?php

namespace App\Filament\Resources\FlourPriceResource\Pages;

use App\Filament\Resources\FlourPriceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFlourPrices extends ListRecords
{
    protected static string $resource = FlourPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
