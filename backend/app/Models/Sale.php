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
        'customer_id',
        'amount',
        'settled_on',
        'bank_account_id',
        'note',
    ];

    /** Payment types that leave money owed until it is collected. */
    public const DEBT_TYPES = ['credit', 'schools'];

    protected function casts(): array
    {
        return ['settled_on' => 'date'];
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

    public function getIsDebtAttribute(): bool
    {
        return in_array($this->payment_type, self::DEBT_TYPES, true);
    }

    public function getIsSettledAttribute(): bool
    {
        return $this->settled_on !== null;
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
