<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\PostsToBankAccount;
use App\Models\Concerns\RecordsAudit;
use App\Support\AppCalendar;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * One repayment against a loan.
 *
 * Paying an instalment takes money out of the account it was paid from, the
 * same as any other cost — a repayment that left the loan smaller without
 * leaving the bank smaller would make the shop look richer for paying its
 * debts.
 */
class LoanPayment extends Model
{
    use BelongsToBakery, PostsToBankAccount, RecordsAudit;

    protected $fillable = [
        'loan_id',
        'user_id',
        'bank_account_id',
        'amount',
        'paid_on',
        'note',
    ];

    /**
     * Every write forgets every remembered loan total, so no copy
     * of a loan anywhere in the request keeps reading the figure
     * from before this row.
     */
    protected static function booted(): void
    {
        static::created(fn () => Loan::forgetLedgerTotals());
        static::updated(fn () => Loan::forgetLedgerTotals());
        static::deleted(fn () => Loan::forgetLedgerTotals());
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
        ];
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function getAmountFormattedAttribute(): string
    {
        return Money::format($this->amount);
    }

    public function getPaidOnDisplayAttribute(): ?string
    {
        return AppCalendar::date($this->paid_on);
    }

    public function bankPostingAccountId(): ?int
    {
        // خالی یعنی «از حساب سفید» — همان جوابی که مالک برای مساعده و
        // حقوق داد و برای قسط هم داد.
        //
        // تا امروز خالی هیچ حسابی را سبک نمی‌کرد: وام کوچک می‌شد، هیچ
        // موجودی‌ای پایین نمی‌آمد، و نانوایی بابتِ پرداختِ بدهی‌اش
        // پولدارتر به نظر می‌رسید. تستِ خودِ همین فایل سال‌ها این را
        // نوشته بود و کسی نمی‌خواندش.
        //
        // قسطی که واقعاً نقدی از کشو داده شده، صندوق را روی ردیف خودش
        // نام می‌برد — و فرمِ پنل حالا اجباری‌اش کرده، پس این fallback
        // فقط ردیف‌های قدیمی و مسیرهای غیرِفرم را می‌گیرد.
        return $this->bank_account_id ?? BankAccount::mainBank()?->id;
    }

    public function bankPostingAmount(): float
    {
        return (float) $this->amount;
    }

    public function bankPostingReason(): string
    {
        return 'loan';
    }

    public function bankPostingDate()
    {
        return $this->paid_on;
    }
}
