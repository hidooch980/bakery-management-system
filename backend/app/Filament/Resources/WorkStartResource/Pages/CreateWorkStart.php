<?php

namespace App\Filament\Resources\WorkStartResource\Pages;

use App\Filament\Resources\WorkStartResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkStart extends CreateRecord
{
    protected static string $resource = WorkStartResource::class;

    // Filament's default sends the admin to the edit page after creating,
    // which — inconsistently applied across the panel — read like a broken
    // "create" button that never returns to the list. Every create page
    // now goes back to the list uniformly.
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
