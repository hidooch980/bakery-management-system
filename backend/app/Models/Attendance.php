<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use BelongsToBakery;

    protected $fillable = [
        'user_id',
        'date',
        'checked_in_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'checked_in_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
