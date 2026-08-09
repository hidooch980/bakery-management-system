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
        'recorded_by',
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

    /**
     * Who entered the tick, when it was not the person themselves.
     *
     * Null means they marked their own arrival. An attendance record is
     * worth nothing if the two cases look identical: a seller ticking the
     * whole floor at once has to be readable as exactly that.
     */
    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function getWasRecordedByAnotherAttribute(): bool
    {
        return $this->recorded_by !== null;
    }
}
