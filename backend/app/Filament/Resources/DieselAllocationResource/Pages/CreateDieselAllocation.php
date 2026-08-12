<?php

namespace App\Filament\Resources\DieselAllocationResource\Pages;

use App\Filament\Resources\DieselAllocationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDieselAllocation extends CreateRecord
{
    protected static string $resource = DieselAllocationResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
