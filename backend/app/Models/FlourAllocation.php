<?php

namespace App\Models;

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
    /**
     * Period boundaries as Jalali days of the month. The third period runs
     * from the 25th into the 4th of the following month.
     */
    public const PERIODS = [
        1 => ['label' => 'دوره اول (۵ تا ۱۴)', 'from' => 5, 'to' => 14],
        2 => ['label' => 'دوره دوم (۱۵ تا ۲۴)', 'from' => 15, 'to' => 24],
        3 => ['label' => 'دوره سوم (۲۵ تا ۴ ماه بعد)', 'from' => 25, 'to' => 4],
    ];

    protected $fillable = ['month_start', 'month_label', 'total_bags', 'total_kg', 'note'];

    protected function casts(): array
    {
        return [
            'month_start' => 'date',
            'total_bags' => 'decimal:2',
            'total_kg' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        // Quotas are issued in sacks; the weight follows from the bag weight
        // the admin configured, so the two can never disagree.
        static::saving(function (self $allocation) {
            if ($allocation->total_bags !== null) {
                $allocation->total_kg = round(
                    (float) $allocation->total_bags * DoughFormula::fromBakery()->bagWeightKg,
                    3
                );
            }
        });
    }

    public function periods()
    {
        return $this->hasMany(FlourAllocationPeriod::class)->orderBy('period_number');
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
        return $this->periods
            ->first(fn (FlourAllocationPeriod $p) => $date->betweenIncluded($p->starts_on, $p->ends_on));
    }

    public static function forDate(Carbon $date): ?self
    {
        return static::with('periods')->get()
            ->first(fn (self $allocation) => $allocation->periodFor($date) !== null);
    }
}
