<?php

namespace App\Models;

use App\Support\DoughFormula;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class FlourAllocationPeriod extends Model
{
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

    /** Flour actually consumed inside this period's date range. */
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
            ->whereBetween('created_at', $window)
            ->sum('quantity');

        // Flour given back when an entry was deleted was never really
        // consumed. Counting it would push the period towards its quota
        // for work that no longer exists — and only reversals are netted
        // off, since an ordinary purchase is not a refund of usage.
        $reversed = (float) $flour->movements()
            ->whereIn('reason', ['production_reversal', 'flour_sale_reversal'])
            ->whereBetween('created_at', $window)
            ->sum('quantity');

        return round(max(0, $out - $reversed), 3);
    }

    /**
     * Nanino chane recorded inside this period's window.
     *
     * Nanino is a display figure for production, but the quota is reconciled
     * against it: the flour the nanino system says should have been used is
     * compared with what the period was actually granted.
     */
    public function getNaninoChaneCountAttribute(): int
    {
        $formula = DoughFormula::fromBakery();

        if (! $formula->naninoChaneWeightKg) {
            return 0;
        }

        $weight = (float) ChaneEntry::whereBetween('created_at', [
            $this->starts_on->copy()->startOfDay(),
            $this->ends_on->copy()->endOfDay(),
        ])->sum('nanino_weight_kg');

        return (int) round($weight / $formula->naninoChaneWeightKg);
    }

    /**
     * Flour the nanino output accounts for, working the dough formula
     * backwards: dough weight divided by what one bag yields.
     */
    public function getNaninoFlourKgAttribute(): float
    {
        $formula = DoughFormula::fromBakery();
        $doughPerBag = $formula->doughKg(1);

        if ($doughPerBag <= 0 || ! $formula->naninoChaneWeightKg) {
            return 0.0;
        }

        $doughKg = $this->nanino_chane_count * $formula->naninoChaneWeightKg;

        return round($doughKg / $doughPerBag * $formula->bagWeightKg, 3);
    }

    /**
     * The reconciliation: allocation minus the flour nanino accounts for.
     * Positive means the period was granted more than nanino used.
     */
    public function getNaninoBalanceKgAttribute(): float
    {
        return round((float) $this->allocated_kg - $this->nanino_flour_kg, 3);
    }

    /**
     * How many nanino loaves the flour actually consumed this period should
     * have produced, running the formula forwards from the usage figure.
     */
    public function getExpectedNaninoCountAttribute(): int
    {
        $formula = DoughFormula::fromBakery();

        if ($formula->bagWeightKg <= 0 || ! $formula->naninoChaneWeightKg) {
            return 0;
        }

        // Counted a sack at a time, like the quota figure above, so the two
        // are read against each other on the same footing.
        $perBag = $formula->naninoChaneCount(1) ?? 0;

        return (int) round($this->used_kg / $formula->bagWeightKg * $perBag);
    }

    /**
     * Everything shaped in this period, restated in nanino loaves.
     *
     * Both systems draw on the same dough, so the comparison below has to
     * count both. Measuring only the nanino actually shaped would mark a
     * shop that works entirely in normal chane as losing every bag of
     * flour it used.
     */
    public function getProducedNaninoEquivalentAttribute(): int
    {
        $formula = DoughFormula::fromBakery();

        if (! $formula->naninoChaneWeightKg) {
            return 0;
        }

        // Both weights come off the same rows, so they are summed in one pass
        // rather than querying the same window twice.
        $doughKg = (float) ChaneEntry::whereBetween('created_at', [
            $this->starts_on->copy()->startOfDay(),
            $this->ends_on->copy()->endOfDay(),
        ])->selectRaw(
            'COALESCE(SUM(normal_weight_kg), 0) + COALESCE(SUM(nanino_weight_kg), 0) as dough_kg'
        )->value('dough_kg');

        return (int) floor($doughKg / $formula->naninoChaneWeightKg);
    }

    /**
     * Production minus what the consumed flour should have yielded, in
     * loaves. Negative means the period produced less bread than the flour
     * it burned through can account for.
     */
    public function getNaninoProductionGapAttribute(): int
    {
        return $this->produced_nanino_equivalent - $this->expected_nanino_count;
    }

    /** The same gap in bags, which is how a shortfall is judged. */
    public function getNaninoProductionGapBagsAttribute(): float
    {
        $formula = DoughFormula::fromBakery();
        $perBag = $formula->naninoChaneCount(1);

        if (! $perBag) {
            return 0.0;
        }

        return round($this->nanino_production_gap / $perBag, 2);
    }

    /**
     * Producing less bread than the consumed flour accounts for is always
     * wrong; producing more is only wrong once it passes a whole bag, since
     * rounding and the handling loss make small overshoots normal.
     */
    public function getNaninoProductionStatusAttribute(): string
    {
        if ($this->expected_nanino_count <= 0) {
            return 'unknown';
        }

        return match (true) {
            $this->nanino_production_gap < 0 => 'short',
            $this->nanino_production_gap_bags > 1 => 'over',
            default => 'ok',
        };
    }

    public function getNaninoProductionStatusLabelAttribute(): string
    {
        if ($this->nanino_production_status === 'unknown') {
            // Say which of the two is missing, so it is clear whether this
            // is a setting to fill in or simply a period nobody has
            // started working yet.
            return DoughFormula::fromBakery()->naninoChaneWeightKg
                ? 'مصرف آردی برای این دوره ثبت نشده'
                : 'وزن چانه نانینو در تنظیمات ثبت نشده';
        }

        return match ($this->nanino_production_status) {
            'short' => 'کمتر از مصرف آرد — خطا',
            'over' => 'بیش از یک کیسه اضافه — خطا',
            default => 'مطابق مصرف آرد',
        };
    }

    /**
     * The bread this period's quota comes to.
     *
     * Nanino is the measure here because the card reader is wired into it,
     * so its loaf is the one the outside world counts: 115 sacks at 64
     * loaves a sack is 7,360 loaves for the period, whatever shape the
     * shop actually baked them in.
     */
    public function getAllocatedBreadCountAttribute(): int
    {
        $formula = DoughFormula::fromBakery();

        if ($formula->bagWeightKg <= 0 || ! $formula->naninoChaneWeightKg) {
            return 0;
        }

        // Counted a sack at a time, the way the shop does it. A sack yields
        // 64 whole loaves and a remainder too small to be another one, and
        // that remainder is lost per sack rather than pooling across the
        // period into loaves nobody could have baked.
        $perBag = $formula->naninoChaneCount(1) ?? 0;
        $bags = (float) $this->allocated_kg / $formula->bagWeightKg;

        return (int) round($bags * $perBag);
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
