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
     * Yeast. Only the dry kind, since 1405/06/08.
     *
     * The shop was set up to stock both, on the idea that fresh yeast
     * proves faster and is what winter calls for. In practice every one of
     * the first thirty-one batches was mixed with dry, and the owner asked
     * for the fresh tub to be taken out rather than carried as a choice
     * nobody makes. See the migration that removes it.
     */
    public const YEAST_DRY = 'yeast_dry';

    /**
     * What the shop buys and therefore counts. Dough is not here: it is
     * mixed and shaped the same day, so carrying it as stock only gave the
     * gap between the formula and the scale somewhere to accumulate.
     */
    public const DEFAULTS = [
        self::FLOUR => 'آرد',
        self::SALT => 'نمک',
        self::YEAST_DRY => 'خمیرمایه خشک',
    ];

    /**
     * What one sack of each good weighs, for a shop that has not said.
     *
     * Only what the owner has actually stated: «هر کیسه نمک ۲۵»
     * (2026-08-17) and «خمیر خشک کیسه ۱۰ کیلو» (1405/06/10). Flour is
     * absent because its size lives on the production formula.
     *
     * Without this a newly opened bakery starts in kilograms again,
     * which is the state the shop asked to be rid of.
     *
     * A good added later with no size still reads in kilograms and still
     * refuses a sack count. That path is not dead code because it is the
     * default for anything new — it is just that every good the shop
     * stocks today has now been sized.
     */
    public const DEFAULT_BAG_WEIGHTS = [
        self::SALT => 25,
        self::YEAST_DRY => 10,
    ];

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

    /**
     * The line under which this good is worth mentioning — the owner's own
     * figure, or one sack when he has not set one.
     *
     * The warning was inert for three years' worth of goods because it
     * waited for a number nobody had entered: on 1405/06 the shop ran out
     * of dry yeast three times in nine days and heard nothing until the
     * app refused the dough. A net that needs configuring before it
     * catches anything is not a net.
     *
     * One sack is the unit the shop actually buys in, so it explains
     * itself — «کمتر از یک کیسه مانده» is an instruction, not a
     * reading. It also lands where it should: at 2.33 kg of yeast a day
     * that is four days' warning, and at 7.67 kg of salt, three.
     *
     * Flour is deliberately excluded. It moves 600 kg a day here, so one
     * sack of it is under an hour's baking rather than days of notice, and
     * flour is the one good already watched — by the quota, which knows
     * about the period as well as the balance.
     */
    public function getEffectiveLowThresholdAttribute(): ?float
    {
        if ($this->low_threshold !== null) {
            return (float) $this->low_threshold;
        }

        if ($this->key === self::FLOUR || $this->bag_weight_kg === null) {
            return null;
        }

        return (float) $this->bag_weight_kg;
    }

    /** True when the fallback above is doing the work, not the owner. */
    public function getLowThresholdIsASackAttribute(): bool
    {
        return $this->low_threshold === null
            && $this->effective_low_threshold !== null;
    }

    public function getIsLowAttribute(): bool
    {
        // An empty item is low whether or not anybody set a threshold.
        return $this->is_empty
            || ($this->effective_low_threshold !== null
                && $this->balance <= $this->effective_low_threshold);
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
            [
                'name' => self::DEFAULTS[$key] ?? $key,
                'unit' => 'کیلوگرم',
                'bag_weight_kg' => self::DEFAULT_BAG_WEIGHTS[$key] ?? null,
            ]
        );
    }
}
