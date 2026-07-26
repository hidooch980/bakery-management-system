<?php

namespace App\Models;

use App\Models\Concerns\PostsToBankAccount;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use PostsToBankAccount;

    protected $fillable = [
        'chane_entry_id',
        'user_id',
        'payment_type',
        'bread_count',
        'shortfall_count',
        'shortfall_amount',
        'shortfall_settled_on',
        'amount_difference',
        'cash_settled_on',
        'customer_id',
        'amount',
        'settled_on',
        'bank_account_id',
        'note',
    ];

    /** Payment types that leave money owed until it is collected. */
    public const DEBT_TYPES = ['credit', 'schools'];

    /**
     * Payment types where the seller physically holds the money until they
     * hand it over. Card payments reach the bank on their own, and credit
     * and school sales are the customer's debt, not the seller's.
     */
    public const CASH_TYPES = ['cash', 'home'];

    protected function casts(): array
    {
        return [
            'settled_on' => 'date',
            'shortfall_settled_on' => 'date',
            'shortfall_amount' => 'decimal:2',
            'cash_settled_on' => 'date',
            'amount_difference' => 'decimal:2',
        ];
    }

    public function chaneEntry()
    {
        return $this->belongsTo(ChaneEntry::class);
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

    /** Sales that created a debt and have not been collected yet. */
    public function scopeOutstanding($query)
    {
        return $query->whereIn('payment_type', self::DEBT_TYPES)
            ->whereNull('settled_on');
    }

    /**
     * Sales where the seller accounted for fewer loaves than the batch
     * held — a temporary debt against the seller, separate from the
     * customer-owed debt above, until it's reconciled or written off.
     */
    public function scopeShortfallOutstanding($query)
    {
        return $query->where('shortfall_count', '>', 0)
            ->whereNull('shortfall_settled_on');
    }

    public function getIsDebtAttribute(): bool
    {
        return in_array($this->payment_type, self::DEBT_TYPES, true);
    }

    public function getIsSettledAttribute(): bool
    {
        return $this->settled_on !== null;
    }

    public function getHasShortfallAttribute(): bool
    {
        return (int) $this->shortfall_count > 0;
    }

    // ------------------------------------------- the seller's own account

    /**
     * Sales still sitting on a seller's temporary account: cash they are
     * holding, or a sale whose money did not match the bread it moved.
     * Either way it is unsettled until the seller accounts for it.
     */
    public function scopeSellerAccountOutstanding($query)
    {
        return $query->whereNull('cash_settled_on')
            ->where(function ($q) {
                $q->whereIn('payment_type', self::CASH_TYPES)
                    ->orWhere('amount_difference', '!=', 0);
            });
    }

    public function getIsCashAttribute(): bool
    {
        return in_array($this->payment_type, self::CASH_TYPES, true);
    }

    /** Cash the seller is holding for this sale, before any discrepancy. */
    public function getCashHeldAttribute(): float
    {
        return $this->is_cash ? (float) $this->amount : 0.0;
    }

    /**
     * What this sale puts on the seller's account: the cash they hold, less
     * any shortfall in what they handed over. A sale that took less money
     * than its bread was worth leaves the seller owing the difference.
     */
    public function getSellerAccountAmountAttribute(): float
    {
        return round($this->cash_held - (float) $this->amount_difference, 2);
    }

    public function getIsSellerAccountSettledAttribute(): bool
    {
        return $this->cash_settled_on !== null;
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
        return 'sale';
    }

    public function bankPostingDate()
    {
        return $this->created_at ?? now();
    }
}
