<?php

namespace App\Models\Concerns;

/**
 * A total summed from a ledger, counted once per object per generation.
 *
 * A loan's `paid` and an account's `balance` are sums over their
 * movements, and a screen reads each of them several times — the
 * remaining, the percentage, the next due date all ask «how much is paid»
 * again. Every read was a query, so the issue scanner asked the
 * `loan_payments` table eight times per loan and the shop's ten loans
 * cost eighty questions.
 *
 * The same shape as `InventoryItem::getBalanceAttribute`, and the same
 * rule: every write to the ledger bumps a generation, so a copy of the
 * model that was not the one written through still counts again. The
 * ledger model's `booted()` hooks do the bumping, because a caller that
 * has to remember to invalidate is a caller that will forget once.
 */
trait RemembersLedgerTotal
{
    private static int $ledgerGeneration = 0;

    private ?int $memoTakenAt = null;

    private ?float $ledgerMemo = null;

    /**
     * The sum, counted only when nothing has been written since.
     *
     * A list loaded with the total already on each row (`withSum`, through
     * the model's own scope) hands that figure over instead of counting,
     * once — a write afterwards bumps the generation and the next read
     * counts properly rather than trusting a number from before the write.
     */
    protected function rememberLedgerTotal(callable $count, ?string $preloadedAs = null): float
    {
        if ($this->ledgerMemo === null || $this->memoTakenAt !== self::$ledgerGeneration) {
            $this->ledgerMemo = $preloadedAs !== null
                && $this->memoTakenAt === null
                && array_key_exists($preloadedAs, $this->attributes)
                ? round((float) $this->attributes[$preloadedAs], 2)
                : $count();
            $this->memoTakenAt = self::$ledgerGeneration;
        }

        return $this->ledgerMemo;
    }

    /**
     * Re-read from the database — `refresh()`, `fresh()` hydrating — is a
     * fresh start for the total too, or a test's `$account->refresh()`
     * would get new columns and an old balance.
     */
    public function setRawAttributes(array $attributes, $sync = false)
    {
        $this->ledgerMemo = null;
        $this->memoTakenAt = null;

        return parent::setRawAttributes($attributes, $sync);
    }

    /** Called by the ledger model after every write, anywhere. */
    public static function forgetLedgerTotals(): void
    {
        self::$ledgerGeneration++;
    }
}
