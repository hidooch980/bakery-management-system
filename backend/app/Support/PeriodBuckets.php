<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Splits a date range into the buckets a report is read in: a day at a time,
 * a week at a time, or a month at a time.
 *
 * The shop reads its calendar in Shamsi, so a "week" runs Saturday to Friday
 * and a "month" is a Jalali month, not a Gregorian one. Bucketing on the
 * Gregorian calendar would put the turn of the month in the wrong place and
 * make every monthly figure disagree with the quota it is read beside.
 */
class PeriodBuckets
{
    public const DAY = 'day';

    public const WEEK = 'week';

    public const MONTH = 'month';

    public const GRANULARITIES = [
        self::DAY => 'روزانه',
        self::WEEK => 'هفتگی',
        self::MONTH => 'ماهانه',
    ];

    /** Buckets past this are refused, so one query cannot walk years. */
    private const MAX_BUCKETS = 400;

    public static function label(string $granularity): string
    {
        return self::GRANULARITIES[$granularity] ?? self::GRANULARITIES[self::DAY];
    }

    public static function normalise(?string $granularity): string
    {
        return array_key_exists($granularity, self::GRANULARITIES)
            ? $granularity
            : self::DAY;
    }

    /**
     * @return array<int, array{from: Carbon, to: Carbon, key: string, label: string}>
     */
    public static function build(Carbon $from, Carbon $to, string $granularity): array
    {
        $granularity = self::normalise($granularity);
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        $buckets = [];

        while ($cursor->lte($end) && count($buckets) < self::MAX_BUCKETS) {
            [$start, $finish] = self::spanFor($cursor, $granularity);

            // A range starting mid-bucket reports from where it was asked to
            // start, not from a date the caller never mentioned.
            $start = $start->lt($from) ? $from->copy()->startOfDay() : $start;
            $close = $finish->gt($end) ? $end->copy() : $finish;

            $buckets[] = [
                'from' => $start,
                'to' => $close,
                'key' => $start->toDateString(),
                'label' => self::labelFor($start, $close, $granularity),
            ];

            $cursor = $finish->copy()->addDay()->startOfDay();
        }

        return $buckets;
    }

    /** The whole bucket the given day falls in, ignoring the asked range. */
    private static function spanFor(Carbon $day, string $granularity): array
    {
        return match ($granularity) {
            self::MONTH => Jalali::monthRangeFor($day),
            self::WEEK => self::weekRangeFor($day),
            default => [$day->copy()->startOfDay(), $day->copy()->endOfDay()],
        };
    }

    /**
     * The Shamsi week containing this day: Saturday through Friday.
     *
     * Carbon numbers Saturday 6, so stepping back that many days lands on
     * the Saturday of the same week whatever the locale is set to.
     */
    private static function weekRangeFor(Carbon $day): array
    {
        $start = $day->copy()->startOfDay();

        while ($start->dayOfWeek !== Carbon::SATURDAY) {
            $start->subDay();
        }

        return [$start, $start->copy()->addDays(6)->endOfDay()];
    }

    private static function labelFor(Carbon $from, Carbon $to, string $granularity): string
    {
        return match ($granularity) {
            self::MONTH => Jalali::monthLabel($from),
            self::WEEK => Jalali::date($from).' تا '.Jalali::date($to),
            default => Jalali::date($from),
        };
    }
}
