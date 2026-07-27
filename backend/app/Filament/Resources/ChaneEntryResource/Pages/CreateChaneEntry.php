<?php

namespace App\Filament\Resources\ChaneEntryResource\Pages;

use App\Filament\Resources\ChaneEntryResource;
use App\Models\DoughEntry;
use App\Support\DoughFormula;
use App\Support\ProductionRecorder;
use Illuminate\Database\Eloquent\Model;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

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

        return ProductionRecorder::chane(
            dough: DoughEntry::findOrFail($data['dough_entry_id']),
            userId: (int) $data['user_id'],
            normalWeightKg: (float) ($formula->weightForNormalChane($count) ?? 0),
            naninoWeightKg: (float) ($formula->weightForNaninoChane(
                (int) ($this->data['nanino_chane_count'] ?? 0)
            ) ?? 0),
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
