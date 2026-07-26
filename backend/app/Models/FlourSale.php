<?php

namespace App\Models;

use App\Models\Concerns\PostsToBankAccount;
use App\Support\AppCalendar;
use App\Support\DoughFormula;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Flour sold straight from the warehouse, either loose by the kilo or by
 * the whole sack. Distinct from Sale, which sells baked bread.
 */
class FlourSale extends Model
{
    use PostsToBankAccount;

    public const KG = 'kg';
    public const BAG = 'bag';

    public const UNITS = [
        self::KG => 'کیلوگرم',
        self::BAG => 'کیسه',
    ];

    /** Payment types that leave money owed until it is collected. */
    public const DEBT_TYPES = ['credit', 'schools'];

    protected $fillable = [
        'user_id',
        'customer_id',
        'bank_account_id',
        'unit',
        'quantity',
        'bag_weight_kg',
        'weight_kg',
        'unit_price',
        'amount',
        'payment_type',
        'sold_on',
        'settled_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'sold_on' => 'date',
            'settled_on' => 'date',
            'quantity' => 'decimal:3',
            'bag_weight_kg' => 'decimal:3',
            'weight_kg' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $sale) {
            $sale->sold_on ??= now()->toDateString();
            $sale->unit = in_array($sale->unit, [self::KG, self::BAG], true)
                ? $sale->unit
                : self::KG;

            // A sack sale needs the sack weight to become a kilo figure. It is
            // captured once, here, so a later settings change cannot rewrite
            // the weight of a sale that already happened.
            if ($sale->unit === self::BAG) {
                $sale->bag_weight_kg = (float) ($sale->bag_weight_kg
                    ?: DoughFormula::fromBakery()->bagWeightKg);
            } else {
                $sale->bag_weight_kg = null;
            }

            $sale->weight_kg = $sale->unit === self::BAG
                ? round((float) $sale->quantity * (float) $sale->bag_weight_kg, 3)
                : round((float) $sale->quantity, 3);

            // Both weight and money are derived, never entered by hand.
            $sale->amount = round((float) $sale->quantity * (float) $sale->unit_price, 2);
        });

        // The warehouse is only touched once the sale is real, and the
        // movement is reversed if the sale is deleted.
        static::created(function (self $sale) {
            InventoryItem::ofKey(InventoryItem::FLOUR)->move(
                'out',
                (float) $sale->weight_kg,
                'flour_sale',
                $sale->user_id,
                $sale,
                'فروش آرد',
            );
        });

        static::deleted(function (self $sale) {
            InventoryItem::ofKey(InventoryItem::FLOUR)->move(
                'in',
                (float) $sale->weight_kg,
                'flour_sale_reversal',
                $sale->user_id,
                null,
                'ابطال فروش آرد',
            );
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** Sales that created a debt and have not been collected yet. */
    public function scopeOutstanding($query)
    {
        return $query->whereIn('payment_type', self::DEBT_TYPES)
            ->whereNull('settled_on');
    }

    public function getIsDebtAttribute(): bool
    {
        return in_array($this->payment_type, self::DEBT_TYPES, true);
    }

    public function getUnitLabelAttribute(): string
    {
        return self::UNITS[$this->unit] ?? $this->unit;
    }

    /** e.g. "۳ کیسه (۱۳۵ کیلوگرم)" — the sack count with its kilo equivalent. */
    public function getQuantityLabelAttribute(): string
    {
        $quantity = rtrim(rtrim(number_format((float) $this->quantity, 2), '0'), '.');

        if ($this->unit === self::BAG) {
            return $quantity.' کیسه ('.$this->weight_label.')';
        }

        return $this->weight_label;
    }

    public function getWeightLabelAttribute(): string
    {
        return rtrim(rtrim(number_format((float) $this->weight_kg, 2), '0'), '.').' کیلوگرم';
    }

    public function getAmountFormattedAttribute(): string
    {
        return Money::format($this->amount);
    }

    public function getSoldOnDisplayAttribute(): ?string
    {
        return AppCalendar::date($this->sold_on);
    }

    /**
     * The going rate for one unit, from the bakery settings. A sack price is
     * derived from the kilo price whenever it has not been set explicitly.
     */
    public static function defaultUnitPrice(string $unit): float
    {
        $bakery = Bakery::first();

        if (! $bakery) {
            return 0.0;
        }

        $perKg = (float) $bakery->flour_price_per_kg;

        if ($unit === self::BAG) {
            return (float) ($bakery->flour_price_per_bag
                ?: $perKg * DoughFormula::fromBakery()->bagWeightKg);
        }

        return $perKg;
    }

    // ------------------------------------------------- bank posting

    public function bankPostingAccountId(): ?int
    {
        return $this->bank_account_id;
    }

    public function bankPostingAmount(): float
    {
        return (float) $this->amount;
    }

    public function bankPostingReason(): string
    {
        return 'flour_sale';
    }

    public function bankPostingDate()
    {
        return $this->sold_on ?? now();
    }
}
