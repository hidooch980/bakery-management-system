<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Morilog\Jalali\Jalalian;

/**
 * Single place that turns stored Gregorian timestamps into the Hijri Shamsi
 * strings shown throughout the panel, API and app.
 */
class Jalali
{
    /** e.g. ۱۴۰۵/۰۵/۰۳ */
    public static function date(Carbon|string|null $value): ?string
    {
        return self::format($value, 'Y/m/d');
    }

    /** e.g. ۱۴۰۵/۰۵/۰۳ — ۰۸:۳۰ */
    public static function dateTime(Carbon|string|null $value): ?string
    {
        return self::format($value, 'Y/m/d — H:i');
    }

    /** e.g. ۰۸:۳۰ */
    public static function time(Carbon|string|null $value): ?string
    {
        return self::format($value, 'H:i');
    }

    /** e.g. شنبه ۳ مرداد ۱۴۰۵ */
    public static function longDate(Carbon|string|null $value): ?string
    {
        return self::format($value, 'l j F Y');
    }

    /** e.g. مرداد ۱۴۰۵ */
    public static function monthLabel(Carbon|string|null $value): ?string
    {
        return self::format($value, 'F Y');
    }

    public static function format(Carbon|string|null $value, string $format): ?string
    {
        $carbon = self::toCarbon($value);

        if ($carbon === null) {
            return null;
        }

        return Jalalian::fromCarbon($carbon)->format($format);
    }

    /**
     * Parses a Jalali date such as "1405/05/03" back into a Carbon instance,
     * so filters and API input can accept Shamsi dates.
     */
    public static function parse(?string $jalaliDate): ?Carbon
    {
        if (blank($jalaliDate)) {
            return null;
        }

        $normalised = str_replace(['-', '.'], '/', self::toLatinDigits(trim($jalaliDate)));

        if (! preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $normalised, $m)) {
            return null;
        }

        try {
            $jalalian = Jalalian::fromFormat('Y/m/d', sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]));
        } catch (\Throwable) {
            // Well-formed but not a real date, e.g. 1405/13/40.
            return null;
        }

        // toCarbon() hands back a base Carbon; the app expects Laravel's subclass.
        return Carbon::instance($jalalian->toCarbon());
    }

    /** Start and end of the current Jalali month, as Gregorian timestamps. */
    public static function currentMonthRange(): array
    {
        $now = Jalalian::now();

        return [
            Carbon::instance($now->getFirstDayOfMonth()->toCarbon())->startOfDay(),
            Carbon::instance($now->getEndDayOfMonth()->toCarbon())->endOfDay(),
        ];
    }

    public static function toLatinDigits(string $value): string
    {
        return str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $value
        );
    }

    private static function toCarbon(Carbon|string|null $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
