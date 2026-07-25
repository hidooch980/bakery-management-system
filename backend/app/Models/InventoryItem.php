<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    public const FLOUR = 'flour';
    public const SALT = 'salt';
    public const DOUGH = 'dough';

    public const DEFAULTS = [
        self::FLOUR => 'آرد',
        self::SALT => 'نمک',
        self::DOUGH => 'خمیر',
    ];

    protected $fillable = ['key', 'name', 'unit', 'low_threshold'];

    protected function casts(): array
    {
        return ['low_threshold' => 'decimal:3'];
    }

    public function movements()
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** Current stock, derived from the movement ledger. */
    public function getBalanceAttribute(): float
    {
        $in = (float) $this->movements()->where('direction', 'in')->sum('quantity');
        $out = (float) $this->movements()->where('direction', 'out')->sum('quantity');

        return round($in - $out, 3);
    }

    /** Flour is handled in sacks, so its balance is also useful in bags. */
    public function getBalanceBagsAttribute(): ?float
    {
        if ($this->key !== self::FLOUR) {
            return null;
        }

        $bagWeight = \App\Support\DoughFormula::fromBakery()->bagWeightKg;

        return $bagWeight > 0 ? round($this->balance / $bagWeight, 2) : null;
    }

    public function getIsLowAttribute(): bool
    {
        return $this->low_threshold !== null
            && $this->balance <= (float) $this->low_threshold;
    }

    /** Records a stock movement and returns it. */
    public function move(
        string $direction,
        float $quantity,
        string $reason = 'manual',
        ?int $userId = null,
        ?Model $source = null,
        ?string $note = null,
    ): InventoryMovement {
        return $this->movements()->create([
            'user_id' => $userId,
            'direction' => $direction,
            'quantity' => $quantity,
            'reason' => $reason,
            'source_type' => $source ? $source::class : null,
            'source_id' => $source?->getKey(),
            'note' => $note,
        ]);
    }

    public static function ofKey(string $key): self
    {
        return static::firstOrCreate(
            ['key' => $key],
            ['name' => self::DEFAULTS[$key] ?? $key, 'unit' => 'kg']
        );
    }
}
