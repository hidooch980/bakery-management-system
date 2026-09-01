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

        // Anything that changes what this record says about the flour —
        // settling it, correcting the sacks, or correcting which way they
        // went — is answered by making the warehouse agree with the row
        // again.
        //
        // It used to answer only settling, so editing a record moved
        // nothing: the ledger squared and the flour was somewhere else.
        // That is why the twelve sacks recorded as twelve kilograms had
        // to be deleted and rewritten rather than corrected, and it is
        // the same shape as three other bugs in this project — see the
        // note on the sale whose count could be corrected while the
        // charge stayed put.
        static::updated(function (self $record) {
            if ($record->wasChanged(['settled_on', 'bags', 'amount_kg', 'direction'])) {
                $record->reconcileStock();
            }
        });

        // Deleting the record has to take its stock movement with it, or the
        // warehouse keeps flour that nothing on file accounts for.
        static::deleted(fn (self $record) => StockReversal::of($record, 'ابطال آرد امانی'));
    }

    /**
     * Posts whatever it takes to make the warehouse agree with this row.
     *
     * The effect a consignment should have on the store is a fact about
     * its current state, not about the history of edits that got it
     * there: flour borrowed and not yet given back is in the store,
     * flour lent and not yet returned is out of it, and a settled record
     * has had both halves and nets to nothing.
     *
     * What it has *actually* moved is read from the ledger rather than
     * recomputed, the same way StockReversal reads it — a record made
     * before the model moved stock at all has moved nothing, and
     * inventing its history would conjure flour out of the air.
     *
     * One correcting movement, for the difference. Nothing is rewritten,
     * so the ledger still says what happened and when.
     */
    public function reconcileStock(): void
    {
        $shouldBe = $this->settled_on !== null
            ? 0.0
            : ($this->direction === 'borrowed' ? 1 : -1) * (float) $this->amount_kg;

        $movements = InventoryMovement::where('source_type', self::class)
            ->where('source_id', $this->getKey())
            ->get();

        $actual = 0.0;

        foreach ($movements as $movement) {
            $actual += ($movement->direction === 'in' ? 1 : -1) * (float) $movement->quantity;
        }

        $delta = round($shouldBe - $actual, 3);

        if (abs($delta) < 0.001) {
            return;
        }

        $this->moveStock(
            $delta > 0 ? 'in' : 'out',
            'consignment_return',
            'اصلاح آرد امانی — '.$this->partner_label,
            abs($delta),
        );
    }

    /** Moves the warehouse by this record's weight, tagged back to it. */
    protected function moveStock(string $direction, string $reason, ?string $note = null, ?float $quantity = null): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move(
            $direction,
            $quantity ?? (float) $this->amount_kg,
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
