<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
use App\Support\AppCalendar;
use App\Support\DoughFormula;
use App\Support\Jalali;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A month's diesel quota, and what is left of it.
 *
 * Fuel used to be an expense category and nothing more: money out, with no
 * record of the litres the shop was entitled to. An oven that runs dry
 * mid-bake is not a bookkeeping problem, so the quota is tracked the way
 * flour is.
 */
class DieselAllocation extends Model
{
    use BelongsToBakery, RecordsAudit;

    protected $fillable = [
        'month_start',
        'month_label',
        'total_litres',
        'litres_per_bag',
        'carryover_litres',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'month_start' => 'date',
            'total_litres' => 'decimal:2',
            'litres_per_bag' => 'decimal:2',
            'carryover_litres' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $allocation) {
            if (! $allocation->month_start) {
                return;
            }

            // The label follows the date rather than being typed beside it,
            // so the two can never disagree about which month this is.
            $allocation->month_label ??= Jalali::monthLabel($allocation->month_start);

            // The quota is not negotiated each month — it follows the flour
            // allocation at a fixed rate per sack. Left to be typed, the two
            // drift apart, and the wrong one is always the one nobody checked.
            $allocation->litres_per_bag ??= self::rateInForce();
            $allocation->total_litres ??= self::litresFor($allocation->month_start);
        });
    }

    /**
     * The litres the month's flour quota entitles the shop to.
     *
     * Null when no flour quota is registered for that month: there is no
     * honest figure to derive, and inventing one would put a number on the
     * screen that no docket will ever match.
     */
    public static function litresFor(Carbon $monthStart): ?float
    {
        $flour = FlourAllocation::query()
            ->whereDate('month_start', $monthStart->toDateString())
            ->first();

        if (! $flour || $flour->total_bags === null) {
            return null;
        }

        // Whole litres: the depot issues them that way, so a fraction on
        // screen is a figure no docket will ever match.
        return round((float) $flour->total_bags * self::rateInForce());
    }

    /** The litres a sack currently earns — the default a new month starts from. */
    public static function rateInForce(): float
    {
        return (float) (Bakery::query()->value('diesel_litres_per_bag') ?? 5);
    }

    /**
     * How this month's figure was arrived at, in words.
     *
     * The rate moves month to month, so a bare litre count invites the
     * question this answers.
     */
    public function getDerivationLabelAttribute(): ?string
    {
        $flour = FlourAllocation::query()
            ->whereDate('month_start', $this->month_start->toDateString())
            ->first();

        if (! $flour || $this->litres_per_bag === null) {
            return null;
        }

        return number_format((float) $flour->total_bags, 0).' کیسه × '
            .rtrim(rtrim(number_format((float) $this->litres_per_bag, 2), '0'), '.')
            .' لیتر';
    }

    /**
     * The window this quota covers: the 5th to the 4th of the month after.
     *
     * Not the calendar month. The mill issues flour against a period that
     * starts on the 5th, the depot issues diesel against the same period,
     * and the shop reads both off the same dockets. Counting a calendar
     * month put four days at each end into the wrong quota — a tanker on
     * the 2nd came off the month that had just ended, and was charged to
     * the one that had just begun.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function quotaRange(): array
    {
        [$from] = FlourAllocation::periodRange($this->month_start, 1);
        [, $to] = FlourAllocation::periodRange($this->month_start, 3);

        return [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
    }

    /**
     * The month's diesel split across the three flour delivery periods.
     *
     * The fuel is issued against the flour, and the flour arrives in three
     * lots rather than one, so the litres belong to those same three
     * periods. The shop may still draw the lot in one go — and does — but
     * a single month-wide figure cannot say which part of the month a
     * tanker was drawn against, nor which period is running today.
     *
     * @return array<int, array<string, mixed>>
     */
    public function periods(): array
    {
        $flour = FlourAllocation::query()
            ->whereDate('month_start', $this->month_start->toDateString())
            ->first();

        if (! $flour) {
            return [];
        }

        $rate = (float) ($this->litres_per_bag ?? self::rateInForce());
        $bagWeight = DoughFormula::fromBakery()->bagWeightKg;
        $today = now();
        $out = [];

        foreach (FlourAllocation::PERIODS as $number => $definition) {
            [$from, $to] = FlourAllocation::periodRange($this->month_start, $number);

            $period = $flour->periods->firstWhere('period_number', $number);
            $bags = $period && $bagWeight > 0
                ? round((float) $period->allocated_kg / $bagWeight, 2)
                : 0.0;

            $out[] = [
                'period_number' => $number,
                'label' => $definition['label'],
                'starts_on' => $from->toDateString(),
                'ends_on' => $to->toDateString(),
                'starts_on_label' => AppCalendar::date($from),
                'ends_on_label' => AppCalendar::date($to),
                'bags' => $bags,
                'litres' => round($bags * $rate),
                'delivered_litres' => round((float) DieselDelivery::query()
                    ->whereBetween('received_on', [$from->toDateString(), $to->toDateString()])
                    ->sum('litres'), 2),
                'is_current' => $today->betweenIncluded(
                    $from->copy()->startOfDay(),
                    $to->copy()->endOfDay(),
                ),
            ];
        }

        return $out;
    }

    /** Quota plus anything carried over: what the shop may draw this month. */
    public function getAvailableLitresAttribute(): float
    {
        return round((float) $this->total_litres + (float) $this->carryover_litres, 2);
    }

    /** Litres actually delivered inside this month. */
    public function getDeliveredLitresAttribute(): float
    {
        [$from, $to] = $this->quotaRange();

        return round((float) DieselDelivery::query()
            ->whereBetween('received_on', [$from->toDateString(), $to->toDateString()])
            ->sum('litres'), 2);
    }

    public function getRemainingLitresAttribute(): float
    {
        return round($this->available_litres - $this->delivered_litres, 2);
    }

    /**
     * Litres burned baking this month's flour.
     *
     * The rate is the same one the quota is derived from — the depot
     * allows 6.5 a sack because a sack takes 6.5 to bake — so consumption
     * follows the sacks that went into dough rather than being metered.
     * That is an estimate and reads as one, which is why it is kept apart
     * from the delivered figure rather than folded into it.
     */
    public function getConsumedLitresAttribute(): float
    {
        [$from, $to] = $this->quotaRange();

        $bags = (float) DoughEntry::query()
            ->whereBetween('created_at', [$from, $to])
            ->sum('bag_count');

        return round($bags * (float) ($this->litres_per_bag ?? self::rateInForce()), 2);
    }

    /** Sacks that went into dough this month, which the estimate rests on. */
    public function getBagsBakedAttribute(): float
    {
        [$from, $to] = $this->quotaRange();

        return round((float) DoughEntry::query()
            ->whereBetween('created_at', [$from, $to])
            ->sum('bag_count'), 2);
    }

    /**
     * What should still be in the tank: fuel that arrived, less fuel burned.
     *
     * A different question from [[getRemainingLitresAttribute]], which is
     * how much more the depot will still issue. A month can be out of
     * quota with a full tank, or in credit with an empty one, and running
     * dry mid-bake is the one of the two that stops the oven.
     */
    public function getInTankLitresAttribute(): float
    {
        return round($this->delivered_litres - $this->consumed_litres, 2);
    }

    /** The tank is estimated to be dry, whatever the quota still allows. */
    public function getIsTankEmptyAttribute(): bool
    {
        return $this->in_tank_litres <= 0;
    }

    /**
     * How much of the quota is gone, as a percentage.
     *
     * Capped at 100 for the bar that shows it: a shop that somehow drew more
     * than its quota is over, not 130% of the way along a bar.
     */
    public function getUsedPercentAttribute(): float
    {
        $available = $this->available_litres;

        if ($available <= 0) {
            return 0.0;
        }

        return round(min(100, $this->delivered_litres / $available * 100), 1);
    }

    public function getIsOverdrawnAttribute(): bool
    {
        return $this->remaining_litres < 0;
    }

    /**
     * The quota covering a given day, if one was ever registered.
     *
     * Matched on the period the day falls in, not the calendar month it
     * sits in: the 2nd of a month belongs to the quota that started on the
     * 5th of the month before and has four days left to run. Asking by
     * calendar month on that day would hand back a quota that has not
     * begun, or none at all.
     */
    public static function forDate(Carbon $day): ?self
    {
        return static::query()->get()->first(
            fn (self $allocation) => $day->betweenIncluded(...$allocation->quotaRange())
        );
    }

    public static function current(): ?self
    {
        return static::forDate(now());
    }

    /**
     * How this row names itself in the trail.
     *
     * The log outlives the record: once the row is gone its id points at
     * nothing, and this sentence is all that is left to argue from.
     */
    public function auditSubject(): ?string
    {
        return 'سهمیهٔ گازوئیل '.($this->month_label ?? '');
    }
}
