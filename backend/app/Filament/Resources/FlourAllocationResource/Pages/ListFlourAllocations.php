<?php

namespace App\Filament\Resources\FlourAllocationResource\Pages;

use App\Filament\Resources\FlourAllocationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFlourAllocations extends ListRecords
{
    protected static string $resource = FlourAllocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
