<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Support\DoughFormula;
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
