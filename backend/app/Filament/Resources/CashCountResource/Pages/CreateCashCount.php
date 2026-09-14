<?php

namespace App\Filament\Resources\CashCountResource\Pages;

use App\Filament\Resources\CashCountResource;
use App\Models\BankAccount;
use App\Models\CashCount;
use App\Support\Money;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateCashCount extends CreateRecord
{
    protected static string $resource = CashCountResource::class;

    /** Read off the form before it is dehydrated away. */
    private bool $adjust = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->adjust = (bool) ($this->data['adjust'] ?? false);

        $till = BankAccount::cashBox();

        if (! $till) {
            Notification::make()
                ->danger()
                ->title('حسابی به‌عنوان صندوق نقد تعیین نشده')
                ->body('اول در «حساب‌های بانکی» یکی را «صندوق» علامت بزنید.')
                ->persistent()
                ->send();

            $this->halt();
        }

        $data['bank_account_id'] = $till->id;
        $data['user_id'] = auth()->id();
        $data['counted_at'] = now();
        // Snapshot, not a live figure: a count is a statement about one
        // instant, and recomputing it later against a ledger that has
        // moved on would rewrite history every time the page opened.
        $data['expected_amount'] = round((float) $till->balance, 2);

        return $data;
    }

    protected function handleRecordCreation(array $data): CashCount
    {
        return DB::transaction(function () use ($data) {
            /** @var CashCount $count */
            $count = CashCount::create($data);

            if ($this->adjust && ! $count->is_exact) {
                $gap = $count->difference;

                $count->account->record(
                    $gap > 0 ? 'in' : 'out',
                    abs($gap),
                    'manual',
                    auth()->id(),
                    $count,
                    'اصلاح پس از شمارش صندوق',
                );
            }

            return $count;
        });
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        $count = $this->record;

        if ($count->is_exact) {
            return 'صندوق با دفتر می‌خواند.';
        }

        return ($count->difference > 0 ? 'اضافه ' : 'کسری ')
            .Money::format(abs($count->difference)).' ثبت شد.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
