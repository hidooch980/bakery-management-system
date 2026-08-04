<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\PostsToBankAccount;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * One payout of a partner's cut for one period.
 */
class ShareSettlement extends Model
{
    use BelongsToBakery, PostsToBankAccount;

    protected $fillable = [
        'bakery_share_id',
        'bank_account_id',
        'period_start',
        'period_end',
        'period_label',
        'period_profit',
        'dang',
        'amount',
        'paid_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_on' => 'date',
            'period_profit' => 'decimal:2',
            'dang' => 'decimal:3',
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $settlement) {
            $settlement->period_label ??= Jalali::monthLabel($settlement->period_start) ?? '';

            // The dang is copied off the holder so the payout still makes
            // sense after the shares are later re-arranged.
            if ($settlement->dang === null && $settlement->bakery_share_id) {
                $settlement->dang = BakeryShare::find($settlement->bakery_share_id)?->dang ?? 0;
            }
        });
    }

    public function share()
    {
        return $this->belongsTo(BakeryShare::class, 'bakery_share_id');
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
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

    public function getAmountFormattedAttribute(): string
    {
        return Money::format($this->amount);
    }

    public function getPaidOnDisplayAttribute(): ?string
    {
        return AppCalendar::date($this->paid_on);
    }

    // ------------------------------------------------- bank posting

    /** A settlement only moves money once it is marked paid. */
    public function bankPostingAccountId(): ?int
    {
        return $this->paid_on === null ? null : $this->bank_account_id;
    }

    public function bankPostingAmount(): float
    {
        return (float) $this->amount;
    }

    public function bankPostingReason(): string
    {
        return 'share';
    }

    public function bankPostingDate()
    {
        return $this->paid_on ?? now();
    }
}
