<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Support\DoughFormula;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class FlourAllocationPeriod extends Model
{
    use BelongsToBakery;

    protected $fillable = [
        'flour_allocation_id',
        'period_number',
        'label',
        'starts_on',
        'ends_on',
        'allocated_kg',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'allocated_kg' => 'decimal:3',
        ];
    }

    public function allocation()
    {
        return $this->belongsTo(FlourAllocation::class, 'flour_allocation_id');
    }

    /**
     * Flour actually consumed inside this period's date range.
     *
     * A bakery only ever eats flour two ways: the batch the dough maker
     * kneads, and the flour thrown on the bench while shaping. Flour that
     * leaves the store any other way — sold on, or handed to another shop
     * as consignment — was never baked, so counting it here would spend
     * the period's quota on bread nobody made.
     */
    public const CONSUMING_REASONS = ['production', 'spray'];

    public function getUsedKgAttribute(): float
    {
        $flour = InventoryItem::query()->where('key', InventoryItem::FLOUR)->first();

        if (! $flour) {
            return 0.0;
        }

        $window = [
            $this->starts_on->copy()->startOfDay(),
            $this->ends_on->copy()->endOfDay(),
        ];

        $out = (float) $flour->movements()
            ->where('direction', 'out')
            ->whereIn('reason', self::CONSUMING_REASONS)
            ->whereBetween('created_at', $window)
            ->sum('quantity');

        // Flour given back when an entry was deleted was never really
        // consumed. Counting it would push the period towards its quota
        // for work that no longer exists — and only reversals are netted
        // off, since an ordinary purchase is not a refund of usage.
        $reversed = (float) $flour->movements()
            ->where('reason', 'production_reversal')
            ->whereBetween('created_at', $window)
            ->sum('quantity');

        return round(max(0, $out - $reversed), 3);
    }

    /**
     * Chane is never measured against flour.
     *
     * The shop shapes to the day: a batch comes out as more or fewer pieces
     * depending on the dough, the weather and the hand doing the shaping,
     * and none of that is a loss of flour. Reconciling the quota against a
     * chane count therefore invented a shortfall on any ordinary day, so
     * the period is judged on flour in and flour out alone — and against
     * the card reader, which counts loaves actually sold.
     *
     * The bread this period's quota comes to.
     *
     * Nanino is the measure here because the card reader is wired into it,
     * so its loaf is the one the outside world counts, whatever shape the
     * shop actually baked them in.
     */

    /** Kept for readers of this model; the standard itself lives with the formula. */
    public const NANINO_PER_BAG = DoughFormula::NANINO_PER_BAG;

    public function getAllocatedBreadCountAttribute(): int
    {
        $bagWeight = DoughFormula::fromBakery()->bagWeightKg;

        if ($bagWeight <= 0) {
            return 0;
        }

        $bags = (float) $this->allocated_kg / $bagWeight;

        return (int) round($bags * self::NANINO_PER_BAG);
    }

    /** Loaves rung through the card reader inside this period. */
    public function getCardBreadCountAttribute(): int
    {
        return (int) $this->cardSales()->sum('bread_count');
    }

    /** What those card sales took, for the admin's card turnover figure. */
    public function getCardAmountAttribute(): float
    {
        return round((float) $this->cardSales()->sum('amount'), 2);
    }

    public function getCardAmountFormattedAttribute(): string
    {
        return Money::format($this->card_amount);
    }

    /**
     * The quota's bread, less what the reader sold. Positive means loaves
     * the period paid for that the reader has not accounted for yet.
     */
    public function getBreadRemainderAttribute(): int
    {
        return $this->allocated_bread_count - $this->card_bread_count;
    }

    private function cardSales()
    {
        return Sale::whereIn('payment_type', Sale::BANKED_TYPES)
            ->whereBetween('created_at', [
                $this->starts_on->copy()->startOfDay(),
                $this->ends_on->copy()->endOfDay(),
            ]);
    }

    public function getRemainingKgAttribute(): float
    {
        return round((float) $this->allocated_kg - $this->used_kg, 3);
    }

    public function getUsagePercentAttribute(): float
    {
        $allocated = (float) $this->allocated_kg;

        return $allocated > 0 ? round($this->used_kg / $allocated * 100, 1) : 0.0;
    }

    public function getIsOverAttribute(): bool
    {
        return $this->remaining_kg < 0;
    }

    /** Calendar length of the period, both ends inclusive. */
    public function getTotalDaysAttribute(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /**
     * Days the shop actually operates in this period.
     *
     * There is no standing weekly closure (no "every Friday") — only the
     * dates someone has explicitly registered as a holiday count, whether
     * entered once or generated from a monthly-recurring rule (e.g. the
     * 15th and 25th of every month).
     */
    public function getHolidayDaysAttribute(): int
    {
        return Holiday::whereBetween('date', [
            $this->starts_on->toDateString(),
            $this->ends_on->toDateString(),
        ])->count();
    }

    public function getWorkingDaysAttribute(): int
    {
        return max(0, $this->total_days - $this->holiday_days);
    }

    /** Average kilograms allocated per working day, for pacing the quota. */
    public function getDailyPaceKgAttribute(): float
    {
        return $this->working_days > 0
            ? round((float) $this->allocated_kg / $this->working_days, 3)
            : 0.0;
    }
}
