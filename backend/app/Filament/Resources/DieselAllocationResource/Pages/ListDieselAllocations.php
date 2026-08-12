<?php

namespace App\Filament\Resources\DieselAllocationResource\Pages;

use App\Filament\Resources\DieselAllocationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDieselAllocations extends ListRecords
{
    protected static string $resource = DieselAllocationResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
