<?php

namespace App\Filament\Resources\FlourPriceResource\Pages;

use App\Filament\Resources\FlourPriceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFlourPrice extends CreateRecord
{
    protected static string $resource = FlourPriceResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
