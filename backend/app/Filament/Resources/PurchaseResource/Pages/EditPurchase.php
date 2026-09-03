<?php

namespace App\Filament\Resources\PurchaseResource\Pages;

use App\Filament\Resources\PurchaseResource;
use App\Models\Purchase;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPurchase extends EditRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->hidden(fn (Purchase $record) => $record->payments()->exists()),
        ];
    }

    /**
     * Correcting a line has to move the flour, not just the figure.
     *
     * The line's own hooks do that as each one saves. This runs afterwards
     * for the case they cannot cover: an edit that removed every line
     * leaves nothing to fire a hook, and the invoice would keep both its
     * total and its sacks.
     */
    protected function afterSave(): void
    {
        /** @var Purchase $record */
        $record = $this->record;

        $record->refreshTotals();
    }
}
