<?php

namespace App\Models;

use App\Exceptions\InsufficientStockException;
use App\Support\DoughFormula;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    public const FLOUR = 'flour';

    public const SALT = 'salt';

    /**
     * Yeast, in the two forms the shop keeps. Fresh proves faster, so it is
     * what winter calls for; dry is the rest of the year. Both are bought
     * and both are stocked, so a batch says which one it took rather than
     * the warehouse drawing down whichever it happens to hold.
     */
    public const YEAST_DRY = 'yeast_dry';

    public const YEAST_WET = 'yeast_wet';

    public const YEAST_TYPES = [
        'dry' => self::YEAST_DRY,
        'wet' => self::YEAST_WET,
    ];

    /**
     * What the shop buys and therefore counts. Dough is not here: it is
     * mixed and shaped the same day, so carrying it as stock only gave the
     * gap between the formula and the scale somewhere to accumulate.
     */
    public const DEFAULTS = [
        self::FLOUR => 'آرد',
        self::SALT => 'نمک',
        self::YEAST_DRY => 'خمیرمایه خشک',
        self::YEAST_WET => 'خمیرمایه تر',
    ];

    /** The warehouse item the given kind of yeast is drawn from. */
    public static function forYeastType(string $type): self
    {
        return self::ofKey(self::YEAST_TYPES[$type] ?? self::YEAST_DRY);
    }

    protected $fillable = ['key', 'name', 'unit', 'bag_weight_kg', 'low_threshold'];

    /**
     * Goods kept in kilograms and nothing else. Salt arrives in sacks of no
     * fixed size and dough is never bagged at all, so a bag count for either
     * would be a number nobody weighs.
     */
    public const WEIGHED_ONLY = [self::SALT, self::YEAST_DRY, self::YEAST_WET];

    protected function casts(): array
    {
        return [
            'low_threshold' => 'decimal:3',
            'bag_weight_kg' => 'decimal:3',
        ];
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

    /**
     * Every stocked good is handled in fixed-size sacks, so the balance is
     * also useful expressed as a bag count.
     *
     * Flour's size lives on the bakery's production formula rather than on
     * the item itself — that setting predates this column and every
     * existing install already has it, so it is kept as the source of
     * truth for flour rather than duplicated here.
     */
    public function getBalanceBagsAttribute(): ?float
    {
        if (in_array($this->key, self::WEIGHED_ONLY, true)) {
            return null;
        }

        $bagWeight = $this->key === self::FLOUR
            ? DoughFormula::fromBakery()->bagWeightKg
            : (float) ($this->bag_weight_kg ?? 0);

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
        // The balance is derived from this same ledger, so an "out" bigger
        // than what's on hand would make it go negative — a physical
        // impossibility. Every caller (API, panel quick-entry, production
        // formulas) shares this one check rather than guarding separately.
        if ($direction === 'out' && $quantity > $this->balance) {
            throw new InsufficientStockException($this->name, $this->balance, $quantity, $this->unit);
        }

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
            ['name' => self::DEFAULTS[$key] ?? $key, 'unit' => 'کیلوگرم']
        );
    }
}
