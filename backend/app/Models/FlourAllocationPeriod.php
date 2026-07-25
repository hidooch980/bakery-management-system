<?php

namespace App\Models;

use App\Models\ChaneEntry;
use App\Models\InventoryItem;
use App\Support\DoughFormula;
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

        return round((float) $flour->movements()
            ->where('direction', 'out')
            ->whereBetween('created_at', [
                $this->starts_on->copy()->startOfDay(),
                $this->ends_on->copy()->endOfDay(),
            ])
            ->sum('quantity'), 3);
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
}
