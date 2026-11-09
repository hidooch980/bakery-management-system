<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use Illuminate\Database\Eloquent\Model;

/** یک تغییر آرد امانی که هنوز (یا دیگر) به همکار گفته شده. */
class PartnerFlourChange extends Model
{
    use BelongsToBakery;

    protected $fillable = [
        'bakery_id', 'customer_id', 'subject_type', 'subject_id', 'kind', 'before', 'after', 'status', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'sent_at' => 'datetime'];
    }
}
