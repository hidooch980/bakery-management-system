<?php

namespace App\Models;

use App\Exceptions\InsufficientStockException;
use App\Models\Concerns\BelongsToBakery;
use App\Support\DoughFormula;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use BelongsToBakery;

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
    /**
     * Goods with no fixed package, so a bag count for them would be
     * converted at a figure nobody measures.
     *
     * It used to hold salt and both yeasts on the belief that they are
     * weighed rather than counted. They are weighed *into the dough* —
     * but they arrive in sacks like everything else, and the owner reads
     * his store in sacks: «هر کیسه خمیر ۱۰ کیلو هست، هر کیسه نمک ۲۵».
     * Whether a good has a package is a fact about the good, so it is
     * data now: an item shows bags when its bag weight is known.
     */
    public const WEIGHED_ONLY = [];

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
        $bagWeight = $this->bagWeightKg();

        return $bagWeight > 0 ? round($this->balance / $bagWeight, 2) : null;
    }

    /**
     * The size of one sack of this, or zero when it has no fixed package.
     *
     * Flour's lives on the production formula rather than here — that
     * setting predates this column and every install already has it — so
     * this is the one place that knows the difference.
     */
    public function bagWeightKg(): float
    {
        return $this->key === self::FLOUR
            ? DoughFormula::fromBakery()->bagWeightKg
            : (float) ($this->bag_weight_kg ?? 0);
    }

    /**
     * Nothing left.
     *
     * Kept apart from `is_low` because a threshold is a judgement somebody
     * has to make and most items here have never had one set, while empty
     * is a fact the ledger already knows. Reading emptiness through the
     * threshold is how the dashboard came to report «موجودی کافی» beside
     * 0.0 کیلوگرم of wet yeast — on the same day that yeast stopped the
     * dough.
     */
    public function getIsEmptyAttribute(): bool
    {
        return $this->balance <= 0;
    }

    public function getIsLowAttribute(): bool
    {
        // An empty item is low whether or not anybody set a threshold.
        return $this->is_empty
            || ($this->low_threshold !== null
                && $this->balance <= (float) $this->low_threshold);
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
