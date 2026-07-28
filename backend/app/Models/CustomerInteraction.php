<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One dealing with a customer that was not a sale — a call, a visit, a
 * complaint — and whatever was promised for next time.
 */
class CustomerInteraction extends Model
{
    public const TYPES = [
        'call' => 'تماس تلفنی',
        'visit' => 'ملاقات حضوری',
        'debt_chase' => 'پیگیری بدهی',
        'order' => 'هماهنگی سفارش',
        'complaint' => 'شکایت',
        'other' => 'سایر',
    ];

    protected $fillable = [
        'customer_id',
        'user_id',
        'type',
        'summary',
        'follow_up_on',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'follow_up_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Follow-ups that are still owed, whether or not they are due yet. */
    public function scopeOpen($query)
    {
        return $query->whereNotNull('follow_up_on')->whereNull('completed_at');
    }

    /** Open follow-ups that have come due — the call list for today. */
    public function scopeDue($query)
    {
        return $query->open()->whereDate('follow_up_on', '<=', now()->toDateString());
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getIsOpenAttribute(): bool
    {
        return $this->follow_up_on !== null && $this->completed_at === null;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->is_open && $this->follow_up_on->isPast();
    }
}
