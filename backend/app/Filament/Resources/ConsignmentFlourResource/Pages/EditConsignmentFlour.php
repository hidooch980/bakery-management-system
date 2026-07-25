<?php

namespace App\Filament\Resources\ConsignmentFlourResource\Pages;

use App\Filament\Resources\ConsignmentFlourResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditConsignmentFlour extends EditRecord
{
    protected static string $resource = ConsignmentFlourResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
