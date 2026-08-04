<?php

namespace App\Filament\Resources\FixedAssetResource\Pages;

use App\Filament\Resources\FixedAssetResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFixedAssets extends ListRecords
{
    protected static string $resource = FixedAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
