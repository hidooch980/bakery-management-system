<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of an invoice: a good, how much of it, and at what rate.
 *
 * A line with no good is money without goods — freight, unloading, the
 * mill's own commission. It counts towards the invoice and never reaches
 * the warehouse, which is why `inventory_item_id` is allowed to be null
 * rather than the shop being made to invent a stock item for a lorry.
 *
 * Nothing here is typed twice. Sacks are what is counted off the lorry and
 * kilograms are what the warehouse holds, so the second is derived from
 * the first; the rate and the line total are the same pair, and whichever
 * the invoice states gives the other. Two figures that mean one thing are
 * how this shop came by every ten-times error it has carried.
 *
 * No bakery scope of its own: a line is reached through its purchase, and
 * that is already scoped.
 */
class PurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id',
        'inventory_item_id',
        'title',
        'bags',
        'quantity_kg',
        'unit_price',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'bags' => 'decimal:2',
            'quantity_kg' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $line) {
            $line->deriveWeight();
            $line->deriveMoney();
        });

        // The invoice total and the warehouse are both facts about the
        // lines, so both are re-read whenever a line moves.
        static::saved(fn (self $line) => $line->purchase?->refreshTotals());
        static::deleted(fn (self $line) => $line->purchase?->refreshTotals());
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /**
     * Sacks and kilograms, kept from disagreeing.
     *
     * The sack size is the good's own, which for flour lives on the
     * production formula rather than the item — InventoryItem::bagWeightKg
     * is the one place that knows the difference, so it is asked rather
     * than the column being read directly.
     *
     * A good with no known sack size keeps whatever weight was entered and
     * carries no sack count. A count converted at an invented figure is
     * worse than a plain weight.
     */
    private function deriveWeight(): void
    {
        $bagWeight = $this->item?->bagWeightKg() ?? 0.0;

        if ($bagWeight <= 0) {
            return;
        }

        if ($this->bags !== null) {
            $this->quantity_kg = round((float) $this->bags * $bagWeight, 3);
        } elseif ((float) $this->quantity_kg > 0) {
            $this->bags = round((float) $this->quantity_kg / $bagWeight, 2);
        }
    }

    /**
     * The rate and the line total, kept from disagreeing.
     *
     * Whichever the invoice happens to state gives the other. Some mills
     * bill a rate per kilo, some bill a round figure for the load, and the
     * shop should be able to type in whichever it is holding.
     *
     * A line with no weight at all — freight, unloading — keeps the total
     * it was given and has no rate, because there is nothing to divide by.
     */
    private function deriveMoney(): void
    {
        $kg = (float) $this->quantity_kg;

        if ($kg <= 0) {
            return;
        }

        if ((float) $this->unit_price > 0) {
            $this->amount = round($kg * (float) $this->unit_price, 2);
        } elseif ((float) $this->amount > 0) {
            $this->unit_price = round((float) $this->amount / $kg, 2);
        }
    }

    /** The good's name, or the free text for a line that has no good. */
    public function getLabelAttribute(): string
    {
        return $this->item?->name ?? (string) ($this->title ?? '—');
    }

    /**
     * «۵ کیسه • ۲۰۰ کیلوگرم» — the sack count leads, because that is what
     * was counted at the door; the weight follows for the books.
     */
    public function getQuantityLabelAttribute(): string
    {
        if ((float) $this->quantity_kg <= 0) {
            return '—';
        }

        $weight = rtrim(rtrim(number_format((float) $this->quantity_kg, 1), '0'), '.').' کیلوگرم';

        if ($this->bags === null) {
            return $weight;
        }

        $bags = rtrim(rtrim(number_format((float) $this->bags, 2), '0'), '.');

        return "{$bags} کیسه  •  {$weight}";
    }

    public function getAmountFormattedAttribute(): string
    {
        return Money::format($this->amount);
    }
}
