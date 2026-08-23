<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
use App\Models\Concerns\PostsToBankAccount;
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
        return $this->bank_account_id;
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
