<?php

namespace App\Filament\Resources\ChaneEntryResource\Pages;

use App\Filament\Resources\ChaneEntryResource;
use App\Support\DoughFormula;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateChaneEntry extends CreateRecord
{
    protected static string $resource = ChaneEntryResource::class;

    /**
     * Weight is never typed — it is always the count times the bakery's
     * configured per-chane weight, the same authority the mobile app's
     * chane gir screen answers to. nanino_chane_count is a form-only field
     * (dehydrated(false), no database column of its own); its weight is
     * derived here rather than trusted from any other source.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $formula = DoughFormula::fromBakery();

        // Chane is counted out a tray at a time, so the batch total is the
        // sum of those trays rather than a figure typed on its own — the
        // count can then never disagree with the trays behind it.
        $trays = collect($this->data['trays'] ?? [])
            ->map(fn ($tray) => (int) ($tray['count'] ?? 0))
            ->filter(fn (int $count) => $count > 0)
            ->values();

        $data['chane_count'] = $trays->sum();
        $data['tray_count'] = $trays->isEmpty() ? null : $trays->count();
        $data['tray_counts'] = $trays->isEmpty() ? null : $trays->all();

        $data['normal_weight_kg'] = $formula->weightForNormalChane($data['chane_count']) ?? 0;
        $data['nanino_weight_kg'] = $formula->weightForNaninoChane(
            (int) ($this->data['nanino_chane_count'] ?? 0)
        ) ?? 0;

        return $data;
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
