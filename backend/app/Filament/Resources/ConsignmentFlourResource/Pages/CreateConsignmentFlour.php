<?php

namespace App\Filament\Resources\ConsignmentFlourResource\Pages;

use App\Filament\Resources\ConsignmentFlourResource;
use Filament\Resources\Pages\CreateRecord;

class CreateConsignmentFlour extends CreateRecord
{
    protected static string $resource = ConsignmentFlourResource::class;

    // Filament's default sends the admin to the edit page after creating,
    // which — inconsistently applied across the panel — read like a broken
    // "create" button that never returns to the list. Every create page
    // now goes back to the list uniformly.
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
