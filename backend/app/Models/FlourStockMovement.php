<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use Illuminate\Database\Eloquent\Model;

class FlourStockMovement extends Model
{
    use BelongsToBakery;

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
