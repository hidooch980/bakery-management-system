<?php

namespace App\Filament\Resources\StaffAdvanceResource\Pages;

use App\Filament\Resources\StaffAdvanceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStaffAdvance extends EditRecord
{
    protected static string $resource = StaffAdvanceResource::class;

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
