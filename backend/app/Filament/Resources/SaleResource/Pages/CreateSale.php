<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\SaleResource;
use App\Models\ChaneEntry;
use App\Models\Sale;
use App\Support\Money;
use App\Support\SaleRecorder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSale extends CreateRecord
{
    protected static string $resource = SaleResource::class;

    /**
     * A batch settled in more than one way becomes one sale row per payment
     * type, written together so the batch closes once and its shortfall is
     * counted once. The same recorder the app's sales go through does the
     * work, so the two cannot drift apart on how either is worked out.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $chane = ChaneEntry::findOrFail($data['chane_entry_id']);

        $lines = collect($this->data['payments'] ?? [])
            ->map(fn (array $line) => [
                'payment_type' => $line['payment_type'] ?? 'cash',
                'bread_count' => (int) ($line['bread_count'] ?? 0),
                // Read from the live form state, which is what the admin
                // typed — in the shop's display unit. MoneyInput's own
                // conversion only applies to the dehydrated payload, so it
                // never reaches here; converting it now keeps this figure
                // in the same Toman as the bread price it is measured
                // against. Skip it and the money gap comes out at nine
                // times the sale on a Rial shop.
                'amount' => isset($line['amount']) && $line['amount'] !== ''
                    ? Money::toToman((float) $line['amount'])
                    : null,
                'customer_id' => $line['customer_id'] ?? null,
                'consumed_by_user_id' => $line['consumed_by_user_id'] ?? null,
                'note' => $data['note'] ?? null,
            ])
            ->filter(fn (array $line) => $line['bread_count'] > 0)
            ->values()
            ->all();

        if ($problem = SaleRecorder::problemWith($chane, $lines)) {
            Notification::make()->title($problem)->danger()->send();

            throw ValidationException::withMessages(['data.payments' => $problem]);
        }

        $sales = SaleRecorder::record($chane, $lines, $data['user_id']);

        // Filament wants one record back; the rest are on the list behind it.
        return $sales[0];
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
