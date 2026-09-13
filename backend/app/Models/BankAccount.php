<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RemembersLedgerTotal;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A bank or cash account. The balance is derived from the opening figure
 * plus the movement ledger, never stored, so a transaction can never be
 * recorded without the balance following it.
 */
class BankAccount extends Model
{
    use BelongsToBakery, RemembersLedgerTotal;

    protected $fillable = [
        'title',
        'bank_name',
        'account_number',
        'card_number',
        'iban',
        'opening_balance',
        'is_default',
        'is_cash_box',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'is_default' => 'boolean',
            'is_cash_box' => 'boolean',
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

            // And exactly one drawer. The migration that added the column
            // said so — «two tills is a question with no answer» — and
            // then only the default was ever held to it, so a second one
            // could be flagged and `cashBox()` would answer with whichever
            // came first by id.
            //
            // Separate from the default on purpose: a one-account shop
            // keeps its cash and its takings in the same place, and
            // neither flag should push the other off.
            if ($account->is_cash_box) {
                static::where('id', '!=', $account->id)
                    ->where('is_cash_box', true)
                    ->update(['is_cash_box' => false]);
            }
        });
    }

    public function transactions()
    {
        return $this->hasMany(BankTransaction::class);
    }

    /**
     * Loads the signed ledger total with the rows, so a list of accounts
     * costs one question rather than one per account.
     */
    public function scopeWithBalance(Builder $query): Builder
    {
        return $query
            ->withSum(['transactions as ledger_in' => fn ($q) => $q->where('direction', 'in')], 'amount')
            ->withSum(['transactions as ledger_out' => fn ($q) => $q->where('direction', 'out')], 'amount');
    }

    public function getBalanceAttribute(): float
    {
        // `withSum` cannot add the opening figure, so the preloaded column
        // is the ledger alone and the opening balance goes on here either way.
        if (array_key_exists('ledger_in', $this->attributes) && $this->memoTakenAt === null) {
            return $this->rememberLedgerTotal(
                fn () => round(
                    (float) $this->opening_balance
                    + (float) $this->attributes['ledger_in']
                    - (float) $this->attributes['ledger_out'],
                    2
                )
            );
        }

        return $this->rememberLedgerTotal(function () {
            // One pass over the ledger, signed, rather than one sum each
            // way: the two answers were the same table read twice.
            $net = (float) $this->transactions()
                ->selectRaw("coalesce(sum(case when direction = 'in' then amount else -amount end), 0) as net")
                ->value('net');

            return round((float) $this->opening_balance + $net, 2);
        });
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
    /**
     * The drawer, as opposed to a bank.
     *
     * Found by its flag rather than its name: matching on "صندوق نقد"
     * would hold until someone renamed it on a screen that invites
     * renaming, and cash would quietly stop being recorded again.
     */
    public static function cashBox(): ?self
    {
        return static::active()->where('is_cash_box', true)->first();
    }

    public static function defaultAccount(): ?self
    {
        return static::active()->where('is_default', true)->first()
            ?? static::active()->first();
    }

    /**
     * Where money taken on the reader lands — «درامد کارتخوان فقط بره حساب
     * سفید».
     *
     * The default account, unless that is the drawer. A shop can flag one
     * account as both, and then card takings would be booked as cash in
     * hand: the drawer reads high by every card sale, and the difference is
     * only found by somebody counting the notes and not finding them.
     *
     * Nothing rather than the drawer when there is no bank at all. A card
     * payment that posts nowhere is a gap somebody can still go and look
     * for; one sitting in the till is a figure that looks right and is not.
     */
    public static function cardAccount(): ?self
    {
        $default = static::active()->where('is_default', true)->first();

        if ($default && ! $default->is_cash_box) {
            return $default;
        }

        // No usable default. One bank and there is nothing to decide, so
        // the money goes where it can only have gone. Several and this
        // stops: picking the lowest id would be a guess, and a guess about
        // which account took the money is worse than a gap somebody can
        // still go and look for.
        $banks = static::active()->where('is_cash_box', false)->limit(2)->get();

        return $banks->count() === 1 ? $banks->first() : null;
    }
}
