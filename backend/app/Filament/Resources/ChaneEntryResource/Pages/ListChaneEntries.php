<?php

namespace App\Filament\Resources\ChaneEntryResource\Pages;

use App\Filament\Resources\ChaneEntryResource;
use App\Filament\Widgets\ProductionComparisonOverview;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListChaneEntries extends ListRecords
{
    protected static string $resource = ChaneEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ProductionComparisonOverview::class,
        ];
    }
}
