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
            // Jalali text -> stored Gregorian, when the form saves. Accepts a
            // Gregorian ISO string too: default() below sets the raw state to
            // one, and a form submitted without ever touching the field would
            // otherwise fail to parse its own untouched default.
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === ''
                ? null
                : Jalali::parseFlexible($state)?->toDateString())
            ->rules([
                fn () => function (string $attribute, $value, \Closure $fail) {
                    if (blank($value)) {
                        return;
                    }

                    if (Jalali::parseFlexible($value) === null) {
                        $fail('تاریخ نامعتبر است. قالب درست: ۱۴۰۵/۰۵/۰۱');
                    }
                },
            ]);
    }

    /**
     * Same field, defaulting to today when the form opens empty.
     *
     * The default is Gregorian, not Jalali text: formatStateUsing above
     * converts it for display exactly as it would a value loaded from the
     * database, and dehydrateStateUsing/the validation rule both accept a
     * Gregorian string as well as typed Jalali text — so a form submitted
     * with this field never touched still saves correctly.
     */
    public static function today(string $name, string $label): TextInput
    {
        return self::make($name, $label)
            ->default(Carbon::now()->toDateString());
    }
}
