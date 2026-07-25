<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChaneEntry extends Model
{
    protected $fillable = [
        'dough_entry_id',
        'user_id',
        'chane_count',
        'normal_weight_kg',
        'nanino_weight_kg',
        'spray_flour_kg',
        'status',
    ];

    public function doughEntry()
    {
        return $this->belongsTo(DoughEntry::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sale()
    {
        return $this->hasOne(Sale::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
