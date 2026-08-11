<?php

namespace App\Filament\Resources\StaffAdvanceResource\Pages;

use App\Filament\Resources\StaffAdvanceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStaffAdvances extends ListRecords
{
    protected static string $resource = StaffAdvanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
