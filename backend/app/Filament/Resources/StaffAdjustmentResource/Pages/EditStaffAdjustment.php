<?php

namespace App\Filament\Resources\StaffAdjustmentResource\Pages;

use App\Filament\Resources\StaffAdjustmentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStaffAdjustment extends EditRecord
{
    protected static string $resource = StaffAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
