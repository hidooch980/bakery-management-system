<?php

namespace App\Filament\Resources\FlourPriceResource\Pages;

use App\Filament\Resources\FlourPriceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFlourPrice extends EditRecord
{
    protected static string $resource = FlourPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
