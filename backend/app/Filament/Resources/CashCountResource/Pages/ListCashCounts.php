<?php

namespace App\Filament\Resources\CashCountResource\Pages;

use App\Filament\Resources\CashCountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCashCounts extends ListRecords
{
    protected static string $resource = CashCountResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('شمارش تازه')];
    }
}
