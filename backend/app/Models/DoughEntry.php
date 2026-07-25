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
