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
        // A delivery paid for at the door, and money paid to a mill on
        // account afterwards. Both leave the same account for the same
        // reason, so they read as one line in a statement.
        'purchase' => 'خرید از تأمین‌کننده',
        'salary' => 'حقوق',
        'advance' => 'علی‌الحساب کارکنان',
        'share' => 'سهم شریک',
        'loan' => 'قسط وام',
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

    /**
     * Every write forgets every remembered bankaccount total, so no copy
     * of a bankaccount anywhere in the request keeps reading the figure
     * from before this row.
     */
    protected static function booted(): void
    {
        static::created(fn () => BankAccount::forgetLedgerTotals());
        static::updated(fn () => BankAccount::forgetLedgerTotals());
        static::deleted(fn () => BankAccount::forgetLedgerTotals());
    }

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
