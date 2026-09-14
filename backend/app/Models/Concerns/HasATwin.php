<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Finds a record already filed that looks like this one.
 *
 * «خرید از کارخانه وحدت دوبار ثبت شده» — typed in once at the door and
 * once from the paper that evening, and nothing between them could tell
 * the second from a second lorry. The same happens to an expense: the
 * diesel is paid for, and paid for again that night from the receipt.
 *
 * The money is compared to the Toman. Two records that happen to cost
 * exactly the same on the same day are rarer than one typed twice — and
 * the caller can still say «واقعاً دو تا بود», which is why this only
 * ever reports, and never decides.
 */
trait HasATwin
{
    /** What makes two of these the same thing, apart from the amount. */
    abstract protected function twinScope(Builder $query): void;

    public function twin(): ?static
    {
        $amount = (float) $this->amount;

        if ($amount <= 0) {
            return null;
        }

        return static::query()
            ->whereKeyNot($this->getKey())
            ->where(fn (Builder $q) => $this->twinScope($q))
            ->whereBetween('amount', [$amount - 0.01, $amount + 0.01])
            ->orderBy('id')
            ->first();
    }
}
