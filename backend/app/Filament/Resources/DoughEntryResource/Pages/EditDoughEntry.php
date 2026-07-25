<?php

namespace App\Filament\Resources\DoughEntryResource\Pages;

use App\Filament\Resources\DoughEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDoughEntry extends EditRecord
{
    protected static string $resource = DoughEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
