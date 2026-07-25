<?php

namespace App\Filament\Forms;

use App\Support\Jalali;
use Filament\Forms\Components\Select;
use Illuminate\Support\Carbon;

/**
 * Picks a Jalali month from two dropdowns rather than making the admin type
 * "1405/05/01" and remember that a quota always starts on the first.
 *
 * The stored value is the Gregorian date of that month's first day.
 */
class JalaliMonthInput
{
    private const MONTHS = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
        5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
        9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
    ];

    /** Options are every month from a year back to a year ahead. */
    public static function make(string $name, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->options(self::options())
            ->default(self::currentValue())
            ->searchable()
            ->native(false)
            ->helperText('ماه شمسی که این سهمیه برای آن است.');
    }

    /** Gregorian first-day dates keyed to their Jalali month names. */
    public static function options(): array
    {
        $current = Jalali::format(Carbon::now(), 'Y/m');
        [$year, $month] = array_map('intval', explode('/', $current));

        $options = [];

        // A year either side covers correcting the past and planning ahead.
        for ($offset = -12; $offset <= 12; $offset++) {
            $m = $month + $offset;
            $y = $year + (int) floor(($m - 1) / 12);
            $m = (($m - 1) % 12 + 12) % 12 + 1;

            $date = Jalali::parse(sprintf('%04d/%02d/01', $y, $m));

            if ($date === null) {
                continue;
            }

            $options[$date->toDateString()] = self::MONTHS[$m].' '.$y;
        }

        return $options;
    }

    public static function currentValue(): ?string
    {
        return Jalali::currentMonthRange()[0]->toDateString();
    }
}
