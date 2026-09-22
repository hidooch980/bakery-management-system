<?php

namespace App\Models;

use App\Support\AppCalendar;
use Illuminate\Database\Eloquent\Model;

/**
 * Somebody asking to use this system, and the answer.
 *
 * Not scoped to a bakery, for the same reason [Subscription] is not: an
 * application does not belong to a shop, it is a request that one exist.
 * Only the owner of the head shop reads these.
 */
class BakeryApplication extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'bakery_name',
        'owner_name',
        'phone',
        'email',
        'city',
        'note',
        'status',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
        'bakery_id',
        'ip',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function bakery()
    {
        return $this->belongsTo(Bakery::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status === self::PENDING;
    }

    public function payload(): array
    {
        return [
            'id' => $this->id,
            'bakery_name' => $this->bakery_name,
            'owner_name' => $this->owner_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'city' => $this->city,
            'note' => $this->note,
            'status' => $this->status,
            'status_label' => match ($this->status) {
                self::APPROVED => 'پذیرفته شد',
                self::REJECTED => 'رد شد',
                default => 'در انتظار بررسی',
            },
            'asked_on_label' => AppCalendar::date($this->created_at),
            'reviewed_on_label' => $this->reviewed_at
                ? AppCalendar::date($this->reviewed_at)
                : null,
            'reviewed_by_name' => $this->reviewedBy?->name,
            'rejection_reason' => $this->rejection_reason,
            'bakery_id' => $this->bakery_id,
        ];
    }
}
