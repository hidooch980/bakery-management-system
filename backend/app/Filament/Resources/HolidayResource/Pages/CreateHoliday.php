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
}
