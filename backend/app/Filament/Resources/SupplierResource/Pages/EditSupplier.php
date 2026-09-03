<?php

namespace App\Filament\Resources\SupplierResource\Pages;

use App\Filament\Resources\SupplierResource;
use App\Models\Supplier;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                // History outranks tidiness: an invoice whose supplier has
                // been removed is an invoice the shop cannot be audited on.
                ->hidden(fn (Supplier $record) => $record->purchases()->exists()
                    || $record->payments()->exists()),
        ];
    }
}
