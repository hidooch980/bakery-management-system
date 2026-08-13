<?php

namespace App\Filament\Resources\CustomerInteractionResource\Pages;

use App\Filament\Resources\CustomerInteractionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomerInteraction extends CreateRecord
{
    protected static string $resource = CustomerInteractionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Who spoke to them, so a promise has a name against it.
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
