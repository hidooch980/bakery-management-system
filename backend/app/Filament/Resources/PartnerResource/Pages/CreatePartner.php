<?php

namespace App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Resources\PartnerResource;
use App\Models\Customer;
use Filament\Resources\Pages\CreateRecord;

class CreatePartner extends CreateRecord
{
    protected static string $resource = PartnerResource::class;

    /** همکار همیشه با نوع «همکار / نانوایی» ساخته می‌شود. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ['type' => Customer::PARTNER_TYPE] + $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
