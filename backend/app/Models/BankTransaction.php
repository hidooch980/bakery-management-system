<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Support\AppCalendar;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class BankTransaction extends Model
{
    use BelongsToBakery;

    public const REASONS = [
        'manual' => 'ثبت دستی',
        'sale' => 'فروش نان',
        'flour_sale' => 'فروش آرد',
        'income' => 'درآمد متفرقه',
        'expense' => 'هزینه',
        'salary' => 'حقوق',
        'advance' => 'علی‌الحساب کارکنان',
        'share' => 'سهم شریک',
        'transfer' => 'انتقال',
        'settlement' => 'وصول بدهی',
    ];

    /** Reasons that bring money in; everything else takes it out. */
    public const INBOUND_REASONS = ['sale', 'flour_sale', 'income', 'settlement'];

    protected $fillable = [
        'bank_account_id',
        'user_id',
        'direction',
        'amount',
        'reason',
        'source_type',
        'source_id',
        'occurred_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_on' => 'date',
        ];
    }

    public function account()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function getReasonLabelAttribute(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }

    public function getAmountFormattedAttribute(): string
    {
        return Money::format($this->amount);
    }

    public function getOccurredOnDisplayAttribute(): ?string
    {
        return AppCalendar::date($this->occurred_on);
    }
}
