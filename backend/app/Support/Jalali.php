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
    /** Years at or above this are Gregorian, not Jalali. */
    private const MAX_JALALI_YEAR = 1700;


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

        // A Gregorian ISO date such as "2026-07-22" matches the pattern above,
        // and reading its year as Jalali would land in the 27th century. Real
        // Jalali years sit well below this, so anything higher is not ours.
        if ((int) $m[1] >= self::MAX_JALALI_YEAR) {
            return null;
        }

        $normalisedInput = sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);

        try {
            $jalalian = Jalalian::fromFormat('Y/m/d', $normalisedInput);
        } catch (\Throwable) {
            // Well-formed but not a real date, e.g. 1405/13/40.
            return null;
        }

        // The library rolls impossible days forward rather than rejecting
        // them — 1405/07/31 silently becomes 1405/08/01. Round-tripping the
        // result catches that, so a day the month does not have is refused.
        if ($jalalian->format('Y/m/d') !== $normalisedInput) {
            return null;
        }

        // toCarbon() hands back a base Carbon; the app expects Laravel's subclass.
        return Carbon::instance($jalalian->toCarbon());
    }

    /**
     * Accepts either a Jalali date ("1405/05/03") or a Gregorian one
     * ("2026-07-25") and returns a Carbon, so API clients can send whichever
     * they hold without the two being confused for each other.
     */
    public static function parseFlexible(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        if ($jalali = self::parse($value)) {
            return $jalali;
        }

        try {
            return Carbon::parse(self::toLatinDigits(trim($value)));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Start and end of the current Jalali month, as Gregorian timestamps. */
    public static function currentMonthRange(): array
    {
        return self::monthRangeFor(Carbon::now());
    }

    /** Start and end of the Jalali month containing the given date. */
    public static function monthRangeFor(Carbon $date): array
    {
        $jalali = Jalalian::fromCarbon($date);

        return [
            Carbon::instance($jalali->getFirstDayOfMonth()->toCarbon())->startOfDay(),
            Carbon::instance($jalali->getEndDayOfMonth()->toCarbon())->endOfDay(),
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

    /**
     * Values reach us in several shapes — a Carbon, a plain "Y-m-d", or a
     * UTC ISO string after a Livewire round-trip. Everything is moved to the
     * app timezone before conversion, otherwise a Tehran midnight serialised
     * as UTC would render as the previous day.
     */
    private static function toCarbon(Carbon|string|null $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->copy()->setTimezone(config('app.timezone'));
        }

        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
