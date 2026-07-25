<?php

namespace App\Models;

use App\Models\InventoryItem;
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
