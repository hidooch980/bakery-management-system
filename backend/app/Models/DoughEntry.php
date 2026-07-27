<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DoughEntry extends Model
{
    protected $fillable = [
        'user_id',
        'bag_count',
        'status',
        'note',
    ];

    protected static function booted(): void
    {
        // Kneading moved real stock, so deleting the entry has to put it
        // back. The original movements stay on the record and a matching
        // pair is added beside them, rather than erasing what happened —
        // an entry deleted without this leaves flour missing from the
        // ledger with nothing left to say where it went.
        static::deleted(function (self $entry) {
            $formula = \App\Support\DoughFormula::fromBakery();
            $bags = (int) $entry->bag_count;

            InventoryItem::ofKey(InventoryItem::FLOUR)->move(
                'in', $formula->flourKg($bags), 'production_reversal',
                $entry->user_id, null, 'ابطال ثبت خمیر',
            );
            InventoryItem::ofKey(InventoryItem::SALT)->move(
                'in', $formula->saltKg($bags), 'production_reversal',
                $entry->user_id, null, 'ابطال ثبت خمیر',
            );
            InventoryItem::ofKey(InventoryItem::DOUGH)->move(
                'out', $formula->doughKg($bags), 'production_reversal',
                $entry->user_id, null, 'ابطال ثبت خمیر',
            );
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
