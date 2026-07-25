<?php

namespace App\Filament\Resources\ChaneEntryResource\Pages;

use App\Filament\Resources\ChaneEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditChaneEntry extends EditRecord
{
    protected static string $resource = ChaneEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
