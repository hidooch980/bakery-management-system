<?php

namespace App\Filament\Resources\SupplierResource\Pages;

use App\Filament\Resources\SupplierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;

    // Every create page in this panel returns to the list, rather than
    // Filament's default of landing on the edit form.
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
