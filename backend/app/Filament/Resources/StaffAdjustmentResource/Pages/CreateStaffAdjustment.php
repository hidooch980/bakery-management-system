<?php

namespace App\Filament\Resources\StaffAdjustmentResource\Pages;

use App\Filament\Resources\StaffAdjustmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStaffAdjustment extends CreateRecord
{
    protected static string $resource = StaffAdjustmentResource::class;

    /** Who wrote it down, so the record can be asked about later. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['recorded_by'] ??= auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
