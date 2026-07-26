<?php

namespace App\Filament\Resources\ChaneEntryResource\Pages;

use App\Filament\Resources\ChaneEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateChaneEntry extends CreateRecord
{
    protected static string $resource = ChaneEntryResource::class;

    // Filament's default sends the admin to the edit page after creating,
    // which — inconsistently applied across the panel — read like a broken
    // "create" button that never returns to the list. Every create page
    // now goes back to the list uniformly.
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
