<?php

namespace App\Filament\Resources\HolidayResource\Pages;

use App\Filament\Resources\HolidayResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditHoliday extends EditRecord
{
    protected static string $resource = HolidayResource::class;

    protected function afterSave(): void
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

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
