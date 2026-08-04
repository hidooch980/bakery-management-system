<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Support\DoughFormula;
use App\Support\StockReversal;
use Illuminate\Database\Eloquent\Model;

/**
 * Flour borrowed from, or lent to, a neighbouring bakery.
 *
 * The sack physically moves, so the warehouse moves with it — and it does so
 * from the model rather than a controller, because the panel, the API and any
 * future caller all have to agree. Recording it in one place and the
 * warehouse in another is how the two end up disagreeing.
 */
class ConsignmentFlour extends Model
{
    use BelongsToBakery;

    // "Flour" is uncountable, so Laravel would guess `consignment_flour`.
    protected $table = 'consignment_flours';

    public const DIRECTIONS = [
        'borrowed' => 'دریافتی از همکار',
        'lent' => 'تحویلی به همکار',
    ];

    protected $fillable = [
        'user_id',
        'customer_id',
        'partner_name',
        'partner_phone',
        'direction',
        'bags',
        'amount_kg',
        'occurred_on',
        'settled_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'bags' => 'decimal:2',
            'amount_kg' => 'decimal:3',
            'occurred_on' => 'date',
            'settled_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // Sacks are what changes hands, so the weight is derived from them
        // and the sack size in settings — never entered beside them, where
        // the two could disagree. A record given only a weight keeps it.
        static::saving(function (self $record) {
            $bagWeight = DoughFormula::fromBakery()->bagWeightKg;

            if ($record->bags !== null && $bagWeight > 0) {
                $record->amount_kg = round((float) $record->bags * $bagWeight, 3);
            } elseif ($record->bags === null && $bagWeight > 0) {
                $record->bags = round((float) $record->amount_kg / $bagWeight, 2);
            }
        });

        // Borrowed flour arrives in the store; lent flour leaves it.
        static::created(fn (self $record) => $record->moveStock(
            $record->direction === 'borrowed' ? 'in' : 'out',
            $record->direction === 'borrowed' ? 'consignment_in' : 'consignment_out',
            $record->partner_label,
        ));

        // Settling means the sacks went back the way they came.
        static::updated(function (self $record) {
            if ($record->wasChanged('settled_on') && $record->settled_on !== null) {
                $record->moveStock(
                    $record->direction === 'borrowed' ? 'out' : 'in',
                    'consignment_return',
                    'تسویه آرد امانی — '.$record->partner_label,
                );
            }
        });

        // Deleting the record has to take its stock movement with it, or the
        // warehouse keeps flour that nothing on file accounts for.
        static::deleted(fn (self $record) => StockReversal::of($record, 'ابطال آرد امانی'));
    }

    /** Moves the warehouse by this record's weight, tagged back to it. */
    protected function moveStock(string $direction, string $reason, ?string $note = null): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move(
            $direction,
            (float) $this->amount_kg,
            $reason,
            $this->user_id,
            $this,
            $note,
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function partner()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** The defined partner's name, or the free-text one for older rows. */
    public function getPartnerLabelAttribute(): string
    {
        return $this->partner?->name ?? (string) $this->partner_name;
    }

    /**
     * "۵ کیسه • ۲۰۰ کیلوگرم" — the sack count leads because that is what
     * was counted at the door; the weight follows for the books.
     */
    public function getQuantityLabelAttribute(): string
    {
        $weight = rtrim(rtrim(number_format((float) $this->amount_kg, 1), '0'), '.').' کیلوگرم';

        if ($this->bags === null) {
            return $weight;
        }

        $bags = rtrim(rtrim(number_format((float) $this->bags, 2), '0'), '.');

        return "{$bags} کیسه  •  {$weight}";
    }

    public function scopeOutstanding($query)
    {
        return $query->whereNull('settled_on');
    }

    public function getIsSettledAttribute(): bool
    {
        return $this->settled_on !== null;
    }

    public function getDirectionLabelAttribute(): string
    {
        return self::DIRECTIONS[$this->direction] ?? $this->direction;
    }
}
