<?php

namespace App\Filament\Resources\FlourSaleResource\Pages;

use App\Filament\Resources\FlourSaleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFlourSales extends ListRecords
{
    protected static string $resource = FlourSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('ثبت فروش آرد')];
    }
}
