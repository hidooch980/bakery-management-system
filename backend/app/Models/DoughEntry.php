<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Support\DoughFormula;
use App\Support\StockLedger;
use App\Support\StockReversal;
use Illuminate\Database\Eloquent\Model;

class DoughEntry extends Model
{
    use BelongsToBakery;

    protected $fillable = [
        'user_id',
        'bag_count',
        'yeast_type',
        'status',
        'note',
    ];

    /** Which yeast this batch was mixed with. */
    public function getYeastTypeLabelAttribute(): string
    {
        return DoughFormula::yeastLabel($this->yeast_type);
    }

    protected static function booted(): void
    {
        // chane_entries cascades on this key, so a bare delete would kill
        // the chane rows inside the database where no model hook runs —
        // their spray flour never reversed, their sales gone with the bank
        // postings left behind. Deleting them here first keeps the whole
        // chain on the model path. (This happened on 06/06: the batch was
        // deleted, the dough's own movements reversed, and the chane's
        // 5 kg of spray flour stayed spent with no owner.)
        static::deleting(function (self $entry) {
            $entry->chaneEntries()->get()->each->delete();
        });

        // Kneading moved real stock, so deleting the entry has to put it
        // back. The original movements stay on the record and a reversing
        // one is added beside each, rather than erasing what happened — an
        // entry deleted without this leaves flour missing from the ledger
        // with nothing left to say where it went.
        static::deleted(function (self $entry) {
            StockReversal::of($entry, 'ابطال ثبت خمیر');
        });

        // And correcting the sack count has to move it too, which for a
        // long time it did not. On 1405/06/07 a batch was entered as ten
        // sacks and corrected to twenty three minutes later; the flour for
        // the other ten was kneaded, shaped into 1,514 chane and sold, and
        // never left the ledger. The store thought it had 400 kg it did
        // not have, and `stock:audit` stayed green throughout, because the
        // entry had moved *some* flour and the audit only asked whether it
        // had moved any.
        static::updated(function (self $entry) {
            if ($entry->wasChanged('bag_count')) {
                $entry->reconcileStock((float) $entry->getOriginal('bag_count'));
            }
        });
    }

    /**
     * Makes the warehouse agree with the sack count this batch now claims.
     *
     * Scaled from what the batch actually moved rather than recomputed
     * from the formula: the shop's salt and yeast ratios have both changed
     * since the older batches were kneaded, and recomputing those against
     * today's ratios would post a correction for a change nobody made. The
     * rate this batch was mixed at is whatever its own movements say, and
     * doubling the sacks doubles that.
     *
     * A batch that moved nothing at all is left alone — there is no rate
     * to scale and inventing one would conjure flour out of the air.
     * `stock:audit` reports those separately, which is the right place for
     * them: a batch with no movement needs a dated correction, not a
     * movement stamped with today's quota period.
     */
    public function reconcileStock(float $bagsBefore): void
    {
        if ($bagsBefore <= 0) {
            return;
        }

        $ratio = (float) $this->bag_count / $bagsBefore;

        $note = sprintf(
            'اصلاح ثبت خمیر — کیسه از %s به %s تغییر کرد.',
            rtrim(rtrim(number_format($bagsBefore, 2), '0'), '.'),
            rtrim(rtrim(number_format((float) $this->bag_count, 2), '0'), '.'),
        );

        foreach ([InventoryItem::FLOUR, InventoryItem::SALT, InventoryItem::YEAST_DRY] as $key) {
            $item = InventoryItem::ofKey($key);
            $moved = StockLedger::netMoved($this, $item->getKey());

            if (abs($moved) < 0.001) {
                continue;
            }

            StockLedger::reconcile(
                $this,
                $item,
                round($moved * $ratio, 3),
                'production',
                $note,
                $this->user_id,
            );
        }
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function chaneEntries()
    {
        return $this->hasMany(ChaneEntry::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
