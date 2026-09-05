<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
use App\Models\Concerns\RemembersLedgerTotal;
use App\Support\AppCalendar;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Money the shop borrowed and is paying back.
 *
 * What is left is counted from the repayments rather than kept as a figure
 * of its own: a remaining balance typed by hand drifts the first time
 * someone pays twice in a month or misses one.
 */
class Loan extends Model
{
    use BelongsToBakery, RecordsAudit, RemembersLedgerTotal;

    protected $fillable = [
        'title',
        'lender',
        'principal',
        'instalment_amount',
        'instalment_count',
        'first_due_on',
        'settled_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'decimal:2',
            'instalment_amount' => 'decimal:2',
            'instalment_count' => 'integer',
            'first_due_on' => 'date',
            'settled_on' => 'date',
        ];
    }

    public function payments()
    {
        return $this->hasMany(LoanPayment::class);
    }

    /** Still being paid back. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('settled_on');
    }

    /**
     * Loads the paid total with the rows, so a list of loans costs one
     * question rather than one per loan.
     */
    public function scopeWithPaid(Builder $query): Builder
    {
        return $query->withSum('payments as paid_total', 'amount');
    }

    public function getPaidAttribute(): float
    {
        return $this->rememberLedgerTotal(
            fn () => round((float) $this->payments()->sum('amount'), 2),
            preloadedAs: 'paid_total',
        );
    }

    /**
     * What is still owed. Never negative: paying more than the principal
     * settles the loan, it does not turn it into money the lender owes.
     */
    public function getRemainingAttribute(): float
    {
        return round(max(0, (float) $this->principal - $this->paid), 2);
    }

    public function getRemainingFormattedAttribute(): string
    {
        return Money::format($this->remaining);
    }

    public function getPaidFormattedAttribute(): string
    {
        return Money::format($this->paid);
    }

    public function getProgressPercentAttribute(): float
    {
        $principal = (float) $this->principal;

        return $principal > 0 ? round($this->paid / $principal * 100, 1) : 0.0;
    }

    /** Instalments paid for, counted at the agreed instalment. */
    public function getInstalmentsPaidAttribute(): int
    {
        $each = (float) $this->instalment_amount;

        return $each > 0 ? (int) floor($this->paid / $each) : 0;
    }

    public function getFirstDueOnDisplayAttribute(): ?string
    {
        return AppCalendar::date($this->first_due_on);
    }

    /**
     * When the next instalment falls due, worked forward from the first by
     * however many have been paid. Null once it is settled or if no
     * schedule was recorded.
     */
    public function getNextDueOnAttribute()
    {
        if ($this->settled_on !== null || $this->first_due_on === null) {
            return null;
        }

        if ($this->remaining <= 0) {
            return null;
        }

        return $this->first_due_on->copy()->addMonths($this->instalments_paid);
    }

    public function getNextDueOnDisplayAttribute(): ?string
    {
        return AppCalendar::date($this->next_due_on);
    }

    /**
     * Past its date and still not paid — the one state worth chasing.
     *
     * Compared against `today()`, not `now()`. `first_due_on` is a date
     * cast, so it is midnight; `isPast()` on it turns true at 00:00 of the
     * due day itself and an instalment due *today* was reported as
     * overdue, at critical, all day — with «۰ روز از سررسید گذشته»
     * underneath it, which says the opposite of what it means. The bank
     * takes the transfer during the day; a due date is a deadline, not a
     * moment.
     */
    public function getIsOverdueAttribute(): bool
    {
        $due = $this->next_due_on;

        return $due !== null && $due->lt(today());
    }
}
