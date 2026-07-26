<?php

namespace App\Filament\Resources\HolidayResource\Pages;

use App\Filament\Resources\HolidayResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateHoliday extends CreateRecord
{
    protected static string $resource = HolidayResource::class;

    protected function afterCreate(): void
    {
        // A monthly shop closure is entered once and generated ahead.
        $created = $this->record->generateFutureOccurrences();

        if ($created > 0) {
            Notification::make()
                ->title("{$created} تعطیلی در ماه‌های آینده ثبت شد.")
                ->success()
                ->send();
        }
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
