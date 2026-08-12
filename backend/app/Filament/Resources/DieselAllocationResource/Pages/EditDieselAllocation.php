<?php

namespace App\Filament\Resources\DieselAllocationResource\Pages;

use App\Filament\Resources\DieselAllocationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDieselAllocation extends EditRecord
{
    protected static string $resource = DieselAllocationResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
