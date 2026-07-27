<?php

namespace App\Filament\Resources\ChaneEntryResource\Pages;

use App\Filament\Resources\ChaneEntryResource;
use App\Support\DoughFormula;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditChaneEntry extends EditRecord
{
    protected static string $resource = ChaneEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * nanino_chane_count has no database column — reverse it from the
     * stored weight so the field is not empty when re-opening a record.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $naninoWeightKg = DoughFormula::fromBakery()->naninoChaneWeightKg;

        $data['nanino_chane_count'] = $naninoWeightKg
            ? (int) round((float) ($data['nanino_weight_kg'] ?? 0) / $naninoWeightKg)
            : 0;

        // Show the trays it was counted into. A batch recorded before trays
        // existed has none stored, so it opens as a single tray holding the
        // whole count rather than an empty form.
        $data['trays'] = collect($data['tray_counts'] ?? [])
            ->map(fn ($count) => ['count' => (int) $count])
            ->whenEmpty(fn () => collect([['count' => (int) ($data['chane_count'] ?? 0)]]))
            ->values()
            ->all();

        return $data;
    }

    /** Same derivation as on create: weight always follows the count. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $formula = DoughFormula::fromBakery();

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
}
