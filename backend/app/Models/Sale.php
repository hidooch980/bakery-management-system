<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\PostsToBankAccount;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use BelongsToBakery, PostsToBankAccount, RecordsAudit;

    /**
     * Every payment type that has ever existed, so an older row still
     * reads as words.
     *
     * Lives here rather than on SaleResource because it is a fact about a
     * sale, not about a table in the panel, and the API needs it too. The
     * resource keeps its own name for it, pointing at this, so the ten
     * call sites across the panel did not have to be disturbed to say the
     * same thing they already said.
     */
    public const PAYMENT_LABELS = [
        'cash' => 'نقد',
        'card' => 'کارتخوان',
        'credit' => 'نسیه',
        'home' => 'منزل',
        'schools' => 'مدارس',
        'charity' => 'خیرات و کمک',
        'shortfall' => 'کسری نان',
        'other' => 'سایر',
    ];

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
     * Bread that leaves without money: donated, or taken home by the staff.
     *
     * No payment is expected for either, so they are left out of the
     * money-gap check and never land on the seller's account. Counting
     * them would put the price of every loaf given away onto the person
     * who handed it over, as though they had pocketed it.
     */
    public const GIVEAWAY_TYPES = ['charity', 'home'];

    /**
     * Bread the seller cannot account for. No money is expected, so it is
     * outside the money-gap check like the giveaways — but unlike them it
     * lands on the seller's account, because the loaves left and nothing
     * came back for them.
     */
    public const SHORTFALL_TYPES = ['shortfall'];

    /**
     * Payment types whose money reaches the bank on its own. A card
     * payment is taken by the reader and settled to the account without
     * anyone carrying it, so it is posted when the sale is recorded —
     * otherwise it sits in neither the seller's hands nor the bank.
     */
    public const BANKED_TYPES = ['card'];

    /**
     * Payment types where the seller physically holds the money until they
     * hand it over. Card payments reach the bank on their own, credit and
     * school sales are the customer's debt rather than the seller's, and
     * bread taken home was never paid for at all.
     */
    public const CASH_TYPES = ['cash'];

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
     * Sales still sitting on a seller's temporary account. Four things can
     * put one there, and a sale may carry more than one at once:
     *
     *   - cash they are still holding
     *   - money that did not match the bread it moved
     *   - bread the batch held that no payment line accounted for
     *   - credit they handed out, until the customer pays
     *
     * The first three the seller settles themselves; credit clears when the
     * customer settles, which is why they are tracked by separate dates.
     */
    public function scopeSellerAccountOutstanding($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($cash) {
                $cash->whereNull('cash_settled_on')
                    ->where(function ($inner) {
                        $inner->whereIn('payment_type', self::CASH_TYPES)
                            ->orWhere('amount_difference', '!=', 0);
                    });
            })
                ->orWhere(function ($short) {
                    $short->whereNull('shortfall_settled_on')
                        ->where('shortfall_count', '>', 0);
                })
                ->orWhere(function ($credit) {
                    $credit->whereNull('settled_on')
                        ->whereIn('payment_type', self::DEBT_TYPES);
                });
        });
    }

    public function getIsCashAttribute(): bool
    {
        return in_array($this->payment_type, self::CASH_TYPES, true);
    }

    /** Cash the seller is holding for this sale, before any discrepancy. */
    public function getCashHeldAttribute(): float
    {
        return $this->is_cash && $this->cash_settled_on === null
            ? (float) $this->amount
            : 0.0;
    }

    /** The money gap, while it is still unsettled. */
    public function getOpenDifferenceAttribute(): float
    {
        return $this->cash_settled_on === null
            ? (float) $this->amount_difference
            : 0.0;
    }

    /** Value of the bread this batch held but nobody paid for. */
    public function getOpenShortfallAttribute(): float
    {
        return $this->has_shortfall && $this->shortfall_settled_on === null
            ? (float) $this->shortfall_amount
            : 0.0;
    }

    /** Credit handed out and not yet collected from the customer. */
    public function getOpenCreditAttribute(): float
    {
        return $this->is_debt && ! $this->is_settled ? (float) $this->amount : 0.0;
    }

    /**
     * What this sale puts on the seller's account. Cash in hand, credit
     * still to collect and bread nobody paid for all count against them;
     * a sale that took less money than its bread was worth adds the gap.
     *
     * Nothing is counted twice: the shortfall is bread no payment line
     * covered, and a sale is either cash or credit, never both.
     */
    public function getSellerAccountAmountAttribute(): float
    {
        return round(
            $this->cash_held
            + $this->open_credit
            + $this->open_shortfall
            - $this->open_difference,
            2
        );
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
