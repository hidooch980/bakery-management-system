<?php

namespace App\Filament\Resources\DieselDeliveryResource\Pages;

use App\Filament\Resources\DieselDeliveryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDieselDelivery extends CreateRecord
{
    protected static string $resource = DieselDeliveryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Who signed for the tanker, for the same reason attendance
        // records who ticked someone in.
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
