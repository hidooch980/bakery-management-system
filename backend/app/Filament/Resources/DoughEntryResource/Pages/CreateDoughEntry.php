<?php

namespace App\Filament\Resources\DoughEntryResource\Pages;

use App\Filament\Resources\DoughEntryResource;
use Filament\Actions;
use App\Support\ProductionRecorder;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDoughEntry extends CreateRecord
{
    protected static string $resource = DoughEntryResource::class;

    /**
     * Kneading consumes flour and salt and produces dough, so recording it
     * here moves the same stock the app's entry does — the panel used to
     * write the row and move nothing, which left the warehouse reading
     * differently depending on where the batch was entered.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return ProductionRecorder::dough(
            (int) $data['bag_count'],
            (int) $data['user_id'],
            $data['note'] ?? null,
        );
    }

    // Filament's default sends the admin to the edit page after creating,
    // which — inconsistently applied across the panel — read like a broken
    // "create" button that never returns to the list. Every create page
    // now goes back to the list uniformly.
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
