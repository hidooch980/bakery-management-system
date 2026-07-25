<?php

namespace App\Filament\Resources\ConsignmentFlourResource\Pages;

use App\Filament\Resources\ConsignmentFlourResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListConsignmentFlour extends ListRecords
{
    protected static string $resource = ConsignmentFlourResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
