<?php

namespace App\Filament\Resources\FlourAllocationResource\Pages;

use App\Filament\Resources\FlourAllocationResource;
use App\Support\Jalali;
use Filament\Resources\Pages\CreateRecord;

class CreateFlourAllocation extends CreateRecord
{
    protected static string $resource = FlourAllocationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['month_label'] = Jalali::monthLabel($data['month_start']) ?? '';

        return $data;
    }

    protected function afterCreate(): void
    {
        // Splitting into the three delivery periods is derived, never entered.
        $this->record->syncPeriods();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
