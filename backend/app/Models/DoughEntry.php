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
        // back. The original movements stay on the record and a reversing
        // one is added beside each, rather than erasing what happened — an
        // entry deleted without this leaves flour missing from the ledger
        // with nothing left to say where it went.
        static::deleted(function (self $entry) {
            \App\Support\StockReversal::of($entry, 'ابطال ثبت خمیر');
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
