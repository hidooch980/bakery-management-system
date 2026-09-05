<?php

namespace App\Models\Concerns;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Keeps a record's bank transaction in step with the record itself.
 *
 * The posting is rebuilt from scratch on every save rather than patched.
 * Editing an amount, moving a sale to a different account, or clearing the
 * account altogether therefore all end at a ledger that matches the record —
 * there is no path where a stale transaction survives an edit.
 */
trait PostsToBankAccount
{
    public static function bootPostsToBankAccount(): void
    {
        static::saved(fn (Model $model) => $model->syncBankTransaction());

        // Deleting the record must take its money movement with it, or the
        // account balance keeps counting a payment that no longer exists.
        static::deleted(fn (Model $model) => $model->clearBankTransactions());
    }

    /** The account this record's money moved through, if any. */
    abstract public function bankPostingAccountId(): ?int;

    /** How much moved, in stored Toman. */
    abstract public function bankPostingAmount(): float;

    /** Why, one of BankTransaction::REASONS. */
    abstract public function bankPostingReason(): string;

    /** When, for the account statement. */
    abstract public function bankPostingDate();

    public function syncBankTransaction(): void
    {
        $this->clearBankTransactions();

        $accountId = $this->bankPostingAccountId();
        $amount = $this->bankPostingAmount();

        // No account named, or nothing to move: nothing to post.
        if ($accountId === null || $amount == 0.0) {
            return;
        }

        $account = BankAccount::find($accountId);

        if (! $account) {
            return;
        }

        $reason = $this->bankPostingReason();

        $account->record(
            in_array($reason, BankTransaction::INBOUND_REASONS, true) ? 'in' : 'out',
            abs($amount),
            $reason,
            $this->user_id ?? null,
            $this,
            null,
            $this->bankPostingDate(),
        );
    }

    public function clearBankTransactions(): void
    {
        BankTransaction::where('source_type', Relation::getMorphAlias(static::class))
            ->where('source_id', $this->getKey())
            ->delete();

        // Deleted through the query builder, so no model event fired and
        // nothing told the accounts their remembered balance is old.
        BankAccount::forgetLedgerTotals();
    }

    public function bankTransactions()
    {
        return $this->morphMany(BankTransaction::class, 'source');
    }
}
