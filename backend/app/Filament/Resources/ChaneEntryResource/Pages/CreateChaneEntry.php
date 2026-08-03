<?php

namespace App\Filament\Resources\ChaneEntryResource\Pages;

use App\Filament\Resources\ChaneEntryResource;
use App\Models\DoughEntry;
use App\Support\DoughFormula;
use App\Support\ProductionRecorder;
use Illuminate\Database\Eloquent\Model;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateChaneEntry extends CreateRecord
{
    protected static string $resource = ChaneEntryResource::class;

    /**
     * Chane is counted out a tray at a time, so the batch total is the sum
     * of those trays rather than a figure typed on its own — the count can
     * then never disagree with the trays behind it. Weight is never typed
     * either: it is always the count times the bakery's configured
     * per-chane weight, the same authority the app answers to.
     *
     * Shaping also spends dough and spray flour, so this goes through the
     * same recorder the app does rather than writing the row alone.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $formula = DoughFormula::fromBakery();

        $trays = collect($this->data['trays'] ?? [])
            ->map(fn ($tray) => (int) ($tray['count'] ?? 0))
            ->filter(fn (int $count) => $count > 0)
            ->values();

        $count = $trays->sum();

        $dough = DoughEntry::findOrFail($data['dough_entry_id']);

        $normalWeight = (float) ($formula->weightForNormalChane($count) ?? 0);
        $naninoWeight = (float) ($formula->weightForNaninoChane(
            (int) ($this->data['nanino_chane_count'] ?? 0)
        ) ?? 0);

        // The recorder refuses a batch shaped into more dough than it made.
        // Raised here as a form error rather than left to throw, or the
        // admin would meet a 500 page instead of being told the count is
        // too high.
        if ($problem = ProductionRecorder::problemWithChane($dough, $normalWeight, $naninoWeight)) {
            Notification::make()->title('ثبت انجام نشد')->body($problem)->danger()->send();

            throw new Halt();
        }

        return ProductionRecorder::chane(
            dough: $dough,
            userId: (int) $data['user_id'],
            normalWeightKg: $normalWeight,
            naninoWeightKg: $naninoWeight,
            sprayFlourKg: (float) ($data['spray_flour_kg'] ?? 0),
            chaneCount: $count,
            trayCount: $trays->isEmpty() ? null : $trays->count(),
            trayCounts: $trays->isEmpty() ? null : $trays->all(),
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
