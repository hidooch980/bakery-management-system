<?php

namespace App\Filament\Resources\BakeryShareResource\Pages;

use App\Filament\Resources\BakeryShareResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBakeryShare extends EditRecord
{
    protected static string $resource = BakeryShareResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->label('حذف')];
    }
}
