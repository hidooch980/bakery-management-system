<?php

namespace App\Filament\Resources\DoughEntryResource\Pages;

use App\Filament\Resources\DoughEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDoughEntries extends ListRecords
{
    protected static string $resource = DoughEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
