<?php

namespace App\Support;

use App\Models\Bakery;
use Illuminate\Support\Carbon;
use IntlCalendar;

/**
 * Formats dates in whichever calendar the bakery has selected.
 *
 * Timestamps are always stored as Gregorian; this only affects display.
 */
class AppCalendar
{
    public const JALALI = 'jalali';

    public const HIJRI = 'hijri';

    public const GREGORIAN = 'gregorian';

    public const OPTIONS = [
        self::JALALI => 'شمسی (هجری خورشیدی)',
        self::HIJRI => 'قمری (هجری قمری)',
        self::GREGORIAN => 'میلادی',
    ];

    private const HIJRI_MONTHS = [
        'محرم', 'صفر', 'ربیع‌الاول', 'ربیع‌الثانی', 'جمادی‌الاول', 'جمادی‌الثانی',
        'رجب', 'شعبان', 'رمضان', 'شوال', 'ذی‌القعده', 'ذی‌الحجه',
    ];

    private static ?string $cached = null;

    public static function current(): string
    {
        return self::$cached ??= Bakery::query()->value('calendar') ?? self::JALALI;
    }

    public static function forgetCache(): void
    {
        self::$cached = null;
    }

    public static function label(?string $calendar = null): string
    {
        return self::OPTIONS[$calendar ?? self::current()] ?? self::OPTIONS[self::JALALI];
    }

    /** e.g. ۱۴۰۵/۰۵/۰۴ · ۱۴۴۸/۰۲/۰۹ · 2026/07/25 */
    public static function date(Carbon|string|null $value, ?string $calendar = null): ?string
    {
        $carbon = self::toCarbon($value);

        if ($carbon === null) {
            return null;
        }

        return match ($calendar ?? self::current()) {
            self::HIJRI => self::hijriDate($carbon),
            self::GREGORIAN => $carbon->format('Y/m/d'),
            default => Jalali::date($carbon),
        };
    }

    public static function dateTime(Carbon|string|null $value, ?string $calendar = null): ?string
    {
        $carbon = self::toCarbon($value);

        if ($carbon === null) {
            return null;
        }

        return self::date($carbon, $calendar).' — '.$carbon->format('H:i');
    }

    public static function time(Carbon|string|null $value): ?string
    {
        return self::toCarbon($value)?->format('H:i');
    }

    /** e.g. مرداد ۱۴۰۵ · صفر ۱۴۴۸ · July 2026 */
    public static function monthLabel(Carbon|string|null $value, ?string $calendar = null): ?string
    {
        $carbon = self::toCarbon($value);

        if ($carbon === null) {
            return null;
        }

        return match ($calendar ?? self::current()) {
            self::HIJRI => self::hijriMonthLabel($carbon),
            self::GREGORIAN => $carbon->format('F Y'),
            default => Jalali::monthLabel($carbon),
        };
    }

    private static function hijriDate(Carbon $carbon): string
    {
        [$year, $month, $day] = self::hijriParts($carbon);

        return sprintf('%04d/%02d/%02d', $year, $month, $day);
    }

    private static function hijriMonthLabel(Carbon $carbon): string
    {
        [$year, $month] = self::hijriParts($carbon);

        return self::HIJRI_MONTHS[$month - 1].' '.$year;
    }

    /**
     * Uses the islamic-civil (tabular) variant, which is deterministic —
     * sighting-based variants would give different answers per region.
     */
    private static function hijriParts(Carbon $carbon): array
    {
        $calendar = IntlCalendar::createInstance(
            $carbon->getTimezone()->getName(),
            'en@calendar=islamic-civil'
        );

        $calendar->setTime($carbon->getTimestamp() * 1000);

        return [
            $calendar->get(IntlCalendar::FIELD_YEAR),
            $calendar->get(IntlCalendar::FIELD_MONTH) + 1,
            $calendar->get(IntlCalendar::FIELD_DAY_OF_MONTH),
        ];
    }

    /** Normalised to the app timezone, for the same reason as Jalali::toCarbon. */
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
