<?php

namespace App\Filament\Resources\WorkStartResource\Pages;

use App\Filament\Resources\WorkStartResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWorkStart extends EditRecord
{
    protected static string $resource = WorkStartResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->label('حذف')];
    }
}
