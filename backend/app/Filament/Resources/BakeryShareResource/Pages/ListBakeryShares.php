<?php

namespace App\Filament\Resources\BakeryShareResource\Pages;

use App\Filament\Resources\BakeryShareResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBakeryShares extends ListRecords
{
    protected static string $resource = BakeryShareResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('افزودن شریک')];
    }
}
