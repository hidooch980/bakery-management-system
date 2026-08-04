<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Support\DoughFormula;
use App\Support\Jalali;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A month's flour quota, split across the three delivery periods the shop
 * works to.
 */
class FlourAllocation extends Model
{
    use BelongsToBakery;

    /**
     * Period boundaries as Jalali days of the month. The third period runs
     * from the 25th into the 4th of the following month.
     */
    public const PERIODS = [
        1 => ['label' => 'دوره اول (۵ تا ۱۴)', 'from' => 5, 'to' => 14],
        2 => ['label' => 'دوره دوم (۱۵ تا ۲۴)', 'from' => 15, 'to' => 24],
        3 => ['label' => 'دوره سوم (۲۵ تا ۴ ماه بعد)', 'from' => 25, 'to' => 4],
    ];

    protected $fillable = [
        'month_start',
        'month_label',
        'total_bags',
        'total_kg',
        'carryover_bags',
        'carryover_kg',
        'carryover_note',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'month_start' => 'date',
            'total_bags' => 'decimal:2',
            'total_kg' => 'decimal:3',
            'carryover_bags' => 'decimal:2',
            'carryover_kg' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        // Quotas are issued in sacks; the weight follows from the bag weight
        // the admin configured, so the two can never disagree.
        static::saving(function (self $allocation) {
            $bagWeight = DoughFormula::fromBakery()->bagWeightKg;

            if ($allocation->total_bags !== null) {
                $allocation->total_kg = round((float) $allocation->total_bags * $bagWeight, 3);
            }

            // Carry-over is entered in sacks too, for the same reason.
            $allocation->carryover_kg = round((float) $allocation->carryover_bags * $bagWeight, 3);
        });
    }

    public function periods()
    {
        return $this->hasMany(FlourAllocationPeriod::class)->orderBy('period_number');
    }

    /**
     * Everything available this month: the month's own quota plus whatever
     * was left over from earlier periods.
     */
    public function getAvailableBagsAttribute(): float
    {
        return round((float) $this->total_bags + (float) $this->carryover_bags, 2);
    }

    public function getAvailableKgAttribute(): float
    {
        return round((float) $this->total_kg + (float) $this->carryover_kg, 3);
    }

    /** Bags each period is entitled to, derived from its share of the weight. */
    public function bagsForPeriod(FlourAllocationPeriod $period): float
    {
        $bagWeight = DoughFormula::fromBakery()->bagWeightKg;

        return $bagWeight > 0 ? round((float) $period->allocated_kg / $bagWeight, 2) : 0.0;
    }

    /**
     * Rebuilds the three periods, splitting the quota evenly and giving any
     * rounding remainder to the last period so the parts always sum to the
     * whole.
     */
    public function syncPeriods(): void
    {
        $monthStart = Carbon::parse($this->month_start);

        // Only the month's own quota is split into periods. Carry-over is a
        // reserve that can be drawn on at any point, not a per-period ration.
        $total = (float) $this->total_kg;

        $share = round($total / 3, 3);
        $lastShare = round($total - ($share * 2), 3);

        foreach (self::PERIODS as $number => $definition) {
            [$startsOn, $endsOn] = self::periodRange($monthStart, $number);

            $this->periods()->updateOrCreate(
                ['period_number' => $number],
                [
                    'label' => $definition['label'],
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'allocated_kg' => $number === 3 ? $lastShare : $share,
                ]
            );
        }
    }

    /**
     * Gregorian range for a Jalali period of the month this allocation covers.
     */
    public static function periodRange(Carbon $monthStart, int $number): array
    {
        $definition = self::PERIODS[$number];

        $jalaliMonth = Jalali::format($monthStart, 'Y/m');
        [$year, $month] = array_map('intval', explode('/', $jalaliMonth));

        $starts = Jalali::parse(sprintf('%04d/%02d/%02d', $year, $month, $definition['from']));

        if ($number === 3) {
            // Wraps into the next Jalali month.
            $nextMonth = $month === 12 ? 1 : $month + 1;
            $nextYear = $month === 12 ? $year + 1 : $year;
            $ends = Jalali::parse(sprintf('%04d/%02d/%02d', $nextYear, $nextMonth, $definition['to']));
        } else {
            $ends = Jalali::parse(sprintf('%04d/%02d/%02d', $year, $month, $definition['to']));
        }

        return [$starts, $ends];
    }

    /** The period covering a given date, if this allocation has one. */
    public function periodFor(Carbon $date): ?FlourAllocationPeriod
    {
        // Both ends are whole days. The dates are stored at midnight, so
        // comparing a moment against them left the last day of a period
        // matching only until 00:00 — from one minute past, the quota
        // screen said no period was running, on the very day the shop was
        // finishing one.
        return $this->periods->first(fn (FlourAllocationPeriod $p) => $date->betweenIncluded(
            $p->starts_on->copy()->startOfDay(),
            $p->ends_on->copy()->endOfDay(),
        ));
    }

    public static function forDate(Carbon $date): ?self
    {
        return static::with('periods')->get()
            ->first(fn (self $allocation) => $allocation->periodFor($date) !== null);
    }

    /**
     * The allocation for the Jalali month a date falls in, regardless of
     * whether any of its periods actually cover that date.
     *
     * Days 1–4 of every Jalali month fall outside all three delivery
     * periods (5–14, 15–24, 25–next 4) unless the previous month's
     * allocation was also entered, since its third period wraps into
     * them. forDate() correctly returns null on one of those days even
     * though the current month's quota was entered — this exists so a
     * "no active period" message can still say the quota is there and
     * say when it starts, rather than implying nothing was recorded.
     */
    public static function forJalaliMonthOf(Carbon $date): ?self
    {
        $jalaliMonth = Jalali::format($date, 'Y/m');

        return static::with('periods')->get()
            ->first(fn (self $allocation) => Jalali::format($allocation->month_start, 'Y/m') === $jalaliMonth);
    }
}
