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
        'checked_out_at',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
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

    /**
     * Minutes between arriving and leaving; null until they have left.
     */
    public function getWorkedMinutesAttribute(): ?int
    {
        if ($this->checked_in_at === null || $this->checked_out_at === null) {
            return null;
        }

        return max(0, (int) floor($this->checked_in_at->diffInSeconds($this->checked_out_at, false) / 60));
    }

    /** «۸ ساعت و ۱۵ دقیقه» — for the panel; empty until they have left. */
    public function getWorkedLabelAttribute(): ?string
    {
        $minutes = $this->worked_minutes;

        if ($minutes === null) {
            return null;
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        $parts = [];

        if ($hours > 0) {
            $parts[] = $hours.' ساعت';
        }

        if ($rest > 0 || $hours === 0) {
            $parts[] = $rest.' دقیقه';
        }

        return implode(' و ', $parts);
    }

    /** The shape every attendance endpoint answers with. */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'date' => $this->date?->toDateString(),
            'checked_in_at' => $this->checked_in_at?->toDateTimeString(),
            'checked_out_at' => $this->checked_out_at?->toDateTimeString(),
            'worked_minutes' => $this->worked_minutes,
        ];
    }
}
