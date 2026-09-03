<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\PostsToBankAccount;
use App\Models\Concerns\RecordsAudit;
use App\Support\Jalali;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Money paid to a supplier after the delivery.
 *
 * What was handed over at the door lives on the invoice itself, because
 * that is one event. This is the other kind: a round figure paid to the
 * mill on account, days later, against no invoice in particular — which
 * is how this shop actually settles with a mill.
 *
 * `purchase_id` is optional for exactly that reason. Naming an invoice is
 * allowed for a shop that pays that way; leaving it blank pays the account
 * down, and the supplier's balance is the same figure either way.
 *
 * The money moves through PostsToBankAccount, which rebuilds the posting
 * on every save — so correcting an amount corrects the account, and
 * deleting the record takes its movement with it.
 */
class SupplierPayment extends Model
{
    use BelongsToBakery, PostsToBankAccount, RecordsAudit;

    protected $fillable = [
        'supplier_id',
        'purchase_id',
        'user_id',
        'amount',
        'paid_on',
        'bank_account_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
        ];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    // ----------------------------------------------------- bank posting

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
        return 'purchase';
    }

    public function bankPostingDate()
    {
        return $this->paid_on ?? now();
    }

    // ---------------------------------------------------------- readings

    public function getAmountFormattedAttribute(): string
    {
        return Money::format($this->amount);
    }

    public function getPaidOnJalaliAttribute(): ?string
    {
        return Jalali::date($this->paid_on);
    }
}
