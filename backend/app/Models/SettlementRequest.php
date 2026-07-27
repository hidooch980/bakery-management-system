<?php

namespace App\Models;

use App\Support\AppCalendar;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * A seller's claim that they have handed their account over, and the
 * admin's answer to it.
 *
 * Sellers cannot clear their own debt — recording it would mean nothing if
 * they could — but they are the ones who know when the money actually
 * changed hands, so the request comes from them and the confirmation from
 * the admin.
 */
class SettlementRequest extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'cash_amount',
        'difference_amount',
        'shortfall_amount',
        'note',
        'confirmed_at',
        'confirmed_by',
        'rejected_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cash_amount' => 'decimal:2',
            'difference_amount' => 'decimal:2',
            'shortfall_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** Requests still waiting on an answer. */
    public function scopePending($query)
    {
        return $query->whereNull('confirmed_at')->whereNull('rejected_at');
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->confirmed_at === null && $this->rejected_at === null;
    }

    public function getIsConfirmedAttribute(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function getIsRejectedAttribute(): bool
    {
        return $this->rejected_at !== null;
    }

    public function getStatusLabelAttribute(): string
    {
        return match (true) {
            $this->is_confirmed => 'تأیید شده',
            $this->is_rejected => 'رد شده',
            default => 'در انتظار تأیید مدیر',
        };
    }

    public function getAmountFormattedAttribute(): string
    {
        return Money::format((float) $this->amount);
    }

    public function getRequestedOnDisplayAttribute(): ?string
    {
        return $this->created_at ? AppCalendar::dateTime($this->created_at) : null;
    }
}
