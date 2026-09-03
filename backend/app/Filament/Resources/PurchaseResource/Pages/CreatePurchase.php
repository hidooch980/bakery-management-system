<?php

namespace App\Filament\Resources\PurchaseResource\Pages;

use App\Filament\Resources\PurchaseResource;
use App\Models\Purchase;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchase extends CreateRecord
{
    protected static string $resource = PurchaseResource::class;

    // Every create page in this panel returns to the list.
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * The lines are saved after the record they hang off, and each one
     * makes the total and the warehouse agree with it as it goes — so by
     * here the figures are already right. This re-reads them once more
     * for the case the repeater came back empty, where no line hook ran
     * and the invoice would otherwise keep a total from nowhere.
     */
    protected function afterCreate(): void
    {
        /** @var Purchase $record */
        $record = $this->record;

        $record->refreshTotals();
    }
}
