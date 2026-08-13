<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
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
    use BelongsToBakery;

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

    /** Quota plus anything carried over: what the shop may draw this month. */
    public function getAvailableLitresAttribute(): float
    {
        return round((float) $this->total_litres + (float) $this->carryover_litres, 2);
    }

    /** Litres actually delivered inside this month. */
    public function getDeliveredLitresAttribute(): float
    {
        [$from, $to] = Jalali::monthRangeFor($this->month_start);

        return round((float) DieselDelivery::query()
            ->whereBetween('received_on', [$from->toDateString(), $to->toDateString()])
            ->sum('litres'), 2);
    }

    public function getRemainingLitresAttribute(): float
    {
        return round($this->available_litres - $this->delivered_litres, 2);
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

    /** The quota covering a given day, if one was ever registered. */
    public static function forDate(Carbon $day): ?self
    {
        [$from] = Jalali::monthRangeFor($day);

        return static::query()->whereDate('month_start', $from->toDateString())->first();
    }

    public static function current(): ?self
    {
        return static::forDate(now());
    }
}
