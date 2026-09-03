<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What flour cost from a given date.
 *
 * The shop used to carry one price, and the cost of goods read it for every
 * period — so entering today's higher price rewrote last month's profit.
 * Here each row is dated, and a bake is costed at the price in force on the
 * day it happened.
 */
class FlourPrice extends Model
{
    use BelongsToBakery;

    protected $fillable = [
        'purchase_id',
        'price_per_kg',
        'effective_from',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'price_per_kg' => 'decimal:2',
            'effective_from' => 'date',
        ];
    }

    /** The invoice that set this rate, when it was not typed by hand. */
    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * The price in force on a given day: the newest row dated no later
     * than it.
     *
     * Returns null when the shop was buying flour before it ever recorded
     * a price. Nothing is guessed — a cost of zero is visibly zero, where
     * an invented figure would quietly skew a month.
     */
    public static function onDate(Carbon $day): ?float
    {
        $price = static::query()
            ->whereDate('effective_from', '<=', $day->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('price_per_kg');

        return $price === null ? null : (float) $price;
    }

    /** The price the shop is buying at today. */
    public static function current(): ?float
    {
        return static::onDate(now());
    }
}
