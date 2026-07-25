<?php

namespace App\Filament\Resources\FlourAllocationResource\Pages;

use App\Filament\Resources\FlourAllocationResource;
use App\Support\Jalali;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFlourAllocation extends EditRecord
{
    protected static string $resource = FlourAllocationResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->label('حذف')];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['month_label'] = Jalali::monthLabel($data['month_start']) ?? '';

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->syncPeriods();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
