<?php

namespace App\Filament\Resources\WorkStartResource\Pages;

use App\Filament\Resources\WorkStartResource;
use App\Filament\Widgets\WorkStartTable;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWorkStarts extends ListRecords
{
    protected static string $resource = WorkStartResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('ثبت دستی')];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            WorkStartTable::class,
        ];
    }
}
