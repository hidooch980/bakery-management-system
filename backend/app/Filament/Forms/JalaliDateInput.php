<?php

namespace App\Filament\Forms;

use App\Support\Jalali;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Carbon;

/**
 * A date field the admin types and reads in the Jalali calendar, while the
 * database keeps a normal Gregorian date.
 *
 * Filament's DatePicker renders a Gregorian calendar with no Jalali mode, so
 * a shop working to Shamsi dates would have to convert every date by hand.
 */
class JalaliDateInput
{
    public static function make(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->placeholder('۱۴۰۵/۰۵/۰۱')
            ->helperText('تاریخ شمسی — مثال: 1405/05/01')
            // Stored Gregorian -> Jalali text, when the form loads.
            ->formatStateUsing(fn ($state) => $state === null || $state === ''
                ? null
                : Jalali::date($state))
            // Jalali text -> stored Gregorian, when the form saves.
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === ''
                ? null
                : Jalali::parse($state)?->toDateString())
            ->rules([
                fn () => function (string $attribute, $value, \Closure $fail) {
                    if (blank($value)) {
                        return;
                    }

                    if (Jalali::parse($value) === null) {
                        $fail('تاریخ نامعتبر است. قالب درست: ۱۴۰۵/۰۵/۰۱');
                    }
                },
            ]);
    }

    /** Same field, defaulting to today when the form opens empty. */
    public static function today(string $name, string $label): TextInput
    {
        return self::make($name, $label)
            ->default(Carbon::now()->toDateString());
    }
}
