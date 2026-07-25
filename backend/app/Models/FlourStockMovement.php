<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlourStockMovement extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'amount_kg',
        'note',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
