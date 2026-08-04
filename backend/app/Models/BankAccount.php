<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A bank or cash account. The balance is derived from the opening figure
 * plus the movement ledger, never stored, so a transaction can never be
 * recorded without the balance following it.
 */
class BankAccount extends Model
{
    use BelongsToBakery;

    protected $fillable = [
        'title',
        'bank_name',
        'account_number',
        'card_number',
        'iban',
        'opening_balance',
        'is_default',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Exactly one account can be the default, so setting a new one
        // clears the previous.
        static::saved(function (self $account) {
            if ($account->is_default) {
                static::where('id', '!=', $account->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function transactions()
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function getBalanceAttribute(): float
    {
        $in = (float) $this->transactions()->where('direction', 'in')->sum('amount');
        $out = (float) $this->transactions()->where('direction', 'out')->sum('amount');

        return round((float) $this->opening_balance + $in - $out, 2);
    }

    public function getBalanceFormattedAttribute(): string
    {
        return Money::format($this->balance);
    }

    /** A negative balance means the account is overdrawn. */
    public function getIsOverdrawnAttribute(): bool
    {
        return $this->balance < 0;
    }

    public function getLabelAttribute(): string
    {
        return $this->bank_name
            ? "{$this->title} — {$this->bank_name}"
            : $this->title;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Records a movement and returns it. */
    public function record(
        string $direction,
        float $amount,
        string $reason = 'manual',
        ?int $userId = null,
        ?Model $source = null,
        ?string $note = null,
        $occurredOn = null,
    ): BankTransaction {
        return $this->transactions()->create([
            'user_id' => $userId,
            'direction' => $direction,
            'amount' => $amount,
            'reason' => $reason,
            'source_type' => $source ? Relation::getMorphAlias($source::class) : null,
            'source_id' => $source?->getKey(),
            'occurred_on' => $occurredOn ?? now(),
            'note' => $note,
        ]);
    }

    /** The account money defaults to, or the only active one. */
    public static function defaultAccount(): ?self
    {
        return static::active()->where('is_default', true)->first()
            ?? static::active()->first();
    }
}
