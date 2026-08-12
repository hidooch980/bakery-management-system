<?php

namespace App\Filament\Resources\DieselDeliveryResource\Pages;

use App\Filament\Resources\DieselDeliveryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDieselDeliveries extends ListRecords
{
    protected static string $resource = DieselDeliveryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
