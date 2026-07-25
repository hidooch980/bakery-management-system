<?php

namespace App\Models;

use App\Support\Jalali;
use Illuminate\Database\Eloquent\Model;

class SalaryPayment extends Model
{
    protected $fillable = [
        'user_id',
        'period_start',
        'period_label',
        'base_amount',
        'bonus',
        'deduction',
        'net_amount',
        'paid_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'paid_on' => 'date',
            'base_amount' => 'decimal:2',
            'bonus' => 'decimal:2',
            'deduction' => 'decimal:2',
            'net_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Net pay is always derived, never entered by hand, so the three
        // components and the total can never disagree.
        static::saving(function (self $payment) {
            $payment->net_amount = (float) $payment->base_amount
                + (float) $payment->bonus
                - (float) $payment->deduction;

            $payment->period_label ??= Jalali::monthLabel($payment->period_start) ?? '';
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopePaid($query)
    {
        return $query->whereNotNull('paid_on');
    }

    public function scopeUnpaid($query)
    {
        return $query->whereNull('paid_on');
    }

    public function getIsPaidAttribute(): bool
    {
        return $this->paid_on !== null;
    }
}
