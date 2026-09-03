<?php

namespace App\Filament\Resources\ConsignmentFlourResource\Pages;

use App\Filament\Resources\ConsignmentFlourResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditConsignmentFlour extends EditRecord
{
    protected static string $resource = ConsignmentFlourResource::class;

    /**
     * Shows the partner's number in the now-required phone box.
     *
     * Rows written before the box existed carry no number of their own,
     * and the partner they point at may well have one. Without this,
     * opening such a row to change anything at all would demand a
     * telephone number that is already on file two screens away.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (blank($data['partner_phone'] ?? null)) {
            $data['partner_phone'] = $this->record->partner?->phone;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
