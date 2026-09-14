<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\HasATwin;
use App\Models\Concerns\PostsToBankAccount;
use App\Models\Concerns\RecordsAudit;
use App\Support\AppCalendar;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Money that came in outside the bread and flour counters — rent from a
 * sub-let, a government subsidy, sale of sacks, and so on.
 */
class Income extends Model
{
    use BelongsToBakery, HasATwin, PostsToBankAccount, RecordsAudit;

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

    /**
     * What makes two of these the same money: the same kind, on the same
     * day. Not the title, for the reason an expense gives — one payment
     * spelled two ways is still one payment.
     *
     * Money in matters more here than anywhere: it now lands in the till
     * by default, so a receipt entered twice puts the drawer ahead of the
     * notes actually in it — and that gap is only ever found by somebody
     * counting and coming up short.
     */
    protected function twinScope(Builder $query): void
    {
        $query->where('category', $this->category)
            ->whereDate('received_on', $this->received_on);
    }

    /** One line naming this income, for a message about it. */
    public function describe(): string
    {
        return '#'.$this->id.' — '.$this->title
            .'، '.AppCalendar::date($this->received_on)
            .'، '.Money::format((float) $this->amount);
    }

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
