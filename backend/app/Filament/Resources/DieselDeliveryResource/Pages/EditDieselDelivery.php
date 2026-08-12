<?php

namespace App\Filament\Resources\DieselDeliveryResource\Pages;

use App\Filament\Resources\DieselDeliveryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDieselDelivery extends EditRecord
{
    protected static string $resource = DieselDeliveryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
