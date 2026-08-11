<?php

namespace App\Filament\Resources\StaffAdvanceResource\Pages;

use App\Filament\Resources\StaffAdvanceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStaffAdvance extends CreateRecord
{
    protected static string $resource = StaffAdvanceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Who handed the money over, for the same reason attendance records
        // who ticked someone in: an entry nobody is named against is not
        // evidence of anything.
        $data['recorded_by'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
