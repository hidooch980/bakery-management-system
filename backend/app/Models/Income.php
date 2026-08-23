<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
use App\Models\Concerns\PostsToBankAccount;
use App\Support\AppCalendar;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Money that came in outside the bread and flour counters — rent from a
 * sub-let, a government subsidy, sale of sacks, and so on.
 */
class Income extends Model
{
    use BelongsToBakery, PostsToBankAccount, RecordsAudit;

    public const CATEGORIES = [
        'subsidy' => 'یارانه و کمک دولتی',
        'rent' => 'اجاره',
        'scrap' => 'فروش ضایعات و کیسه',
        'service' => 'خدمات',
        'partner' => 'تسویه همکار',
        'other' => 'سایر',
    ];

    protected $fillable = [
        'user_id',
        'customer_id',
        'bank_account_id',
        'category',
        'title',
        'amount',
        'received_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $income) {
            $income->received_on ??= now()->toDateString();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function getAmountFormattedAttribute(): string
    {
        return Money::format($this->amount);
    }

    public function getReceivedOnDisplayAttribute(): ?string
    {
        return AppCalendar::date($this->received_on);
    }

    // ------------------------------------------------- bank posting

    public function bankPostingAccountId(): ?int
    {
        return $this->bank_account_id;
    }

    public function bankPostingAmount(): float
    {
        return (float) $this->amount;
    }

    public function bankPostingReason(): string
    {
        return 'income';
    }

    public function bankPostingDate()
    {
        return $this->received_on ?? now();
    }
}
