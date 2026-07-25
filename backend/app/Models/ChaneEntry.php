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

    /**
     * The weight that counts for stock, sales and reporting.
     *
     * Only the normal chane is real output; the nanino figure is recorded
     * for comparison and must stay out of every calculation.
     */
    public function getWeightKgAttribute(): float
    {
        return round((float) $this->normal_weight_kg, 3);
    }
}
