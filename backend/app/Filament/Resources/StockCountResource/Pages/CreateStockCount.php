<?php

namespace App\Filament\Resources\StockCountResource\Pages;

use App\Filament\Resources\StockCountResource;
use App\Models\InventoryItem;
use App\Models\StockCount;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateStockCount extends CreateRecord
{
    protected static string $resource = StockCountResource::class;

    /** از روی فرم خوانده می‌شود پیش از آنکه کنار گذاشته شود. */
    private bool $adjust = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->adjust = (bool) ($this->data['adjust'] ?? false);

        $item = InventoryItem::find($data['inventory_item_id']);

        if (! $item) {
            Notification::make()
                ->danger()
                ->title('کالا پیدا نشد')
                ->persistent()
                ->send();

            $this->halt();
        }

        $bag = $item->bagWeightKg();
        $counted = (float) ($this->data['counted'] ?? 0);

        $data['user_id'] = auth()->id();
        $data['counted_at'] = now();
        // آنچه روی صفحه گفته شد به کیسه بود؛ آنچه ذخیره می‌شود واحد پایه
        // است. وزن کیسه در تنظیمات عوض می‌شود و شمارشی که به کیسه ذخیره
        // شده باشد، آن روز معنی تازه‌ای پیدا می‌کند.
        $data['counted_quantity'] = round($bag > 0 ? $counted * $bag : $counted, 3);
        // عکس لحظه، نه عددی زنده: شمارش حرفی دربارهٔ یک لحظه است، و
        // محاسبهٔ دوباره‌اش در برابر دفتری که جلو رفته، هر بار که صفحه باز
        // شود تاریخ را بازنویسی می‌کند.
        $data['expected_quantity'] = round((float) $item->balance, 3);

        return $data;
    }

    protected function handleRecordCreation(array $data): StockCount
    {
        return DB::transaction(function () use ($data) {
            /** @var StockCount $count */
            $count = StockCount::create($data);

            if ($this->adjust && ! $count->is_exact) {
                $gap = $count->difference;

                $count->item->move(
                    $gap > 0 ? 'in' : 'out',
                    abs($gap),
                    'stocktake',
                    auth()->id(),
                    $count,
                    'اصلاح پس از شمارش انبار',
                );
            }

            return $count;
        });
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        $count = $this->record;

        if ($count->is_exact) {
            return 'انبار با دفتر می‌خواند.';
        }

        return $count->describe().' ثبت شد.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
