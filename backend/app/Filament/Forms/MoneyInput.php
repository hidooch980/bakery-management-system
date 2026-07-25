<?php

namespace App\Filament\Forms;

use App\Support\Money;
use Filament\Forms\Components\TextInput;

/**
 * A money field that is typed and shown in the bakery's display unit while
 * the database keeps everything in Toman.
 *
 * Without this the suffix would say "ریال" next to a value stored as Toman,
 * and every amount entered by a Rial shop would be saved ten times too large.
 */
class MoneyInput
{
    public static function make(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->suffix(fn () => Money::label())
            // Stored Toman -> display unit, when the form loads.
            ->formatStateUsing(fn ($state) => $state === null || $state === ''
                ? null
                : Money::convert($state))
            // Display unit -> stored Toman, when the form saves.
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === ''
                ? null
                : Money::toToman($state));
    }
}
