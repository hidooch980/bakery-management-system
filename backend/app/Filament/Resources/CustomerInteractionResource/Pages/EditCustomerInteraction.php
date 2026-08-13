<?php

namespace App\Filament\Resources\CustomerInteractionResource\Pages;

use App\Filament\Resources\CustomerInteractionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCustomerInteraction extends EditRecord
{
    protected static string $resource = CustomerInteractionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('حذف'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
