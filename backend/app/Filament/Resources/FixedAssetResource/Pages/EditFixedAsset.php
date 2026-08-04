<?php

namespace App\Filament\Resources\FixedAssetResource\Pages;

use App\Filament\Resources\FixedAssetResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFixedAsset extends EditRecord
{
    protected static string $resource = FixedAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
