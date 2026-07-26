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

        return $data;
    }

    /** Same derivation as on create: weight always follows the count. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $formula = DoughFormula::fromBakery();

        $data['normal_weight_kg'] = $formula->weightForNormalChane((int) $data['chane_count']) ?? 0;
        $data['nanino_weight_kg'] = $formula->weightForNaninoChane(
            (int) ($this->data['nanino_chane_count'] ?? 0)
        ) ?? 0;

        return $data;
    }
}
