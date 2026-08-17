<?php

namespace App\Filament\Resources\StaffAdjustmentResource\Pages;

use App\Filament\Resources\StaffAdjustmentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStaffAdjustments extends ListRecords
{
    protected static string $resource = StaffAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('ثبت تشویقی یا تنبیهی'),
        ];
    }
}
