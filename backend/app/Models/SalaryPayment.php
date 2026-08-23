<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\PostsToBankAccount;
use App\Support\Jalali;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class SalaryPayment extends Model
{
    use BelongsToBakery, PostsToBankAccount;

    protected $fillable = [
        'user_id',
        'period_start',
        'period_label',
        'base_amount',
        'bonus',
        'deduction',
        'advance_deduction',
        'net_amount',
        'paid_on',
        'bank_account_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'paid_on' => 'date',
            'base_amount' => 'decimal:2',
            'bonus' => 'decimal:2',
            'deduction' => 'decimal:2',
            'advance_deduction' => 'decimal:2',
            'net_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Net pay is always derived, never entered by hand, so the parts and
        // the total can never disagree.
        static::saving(function (self $payment) {
            $payment->advance_deduction = $payment->advanceToRecover();

            $payment->net_amount = (float) $payment->base_amount
                + (float) $payment->bonus
                - (float) $payment->deduction
                - (float) $payment->advance_deduction;

            $payment->period_label ??= Jalali::monthLabel($payment->period_start) ?? '';
        });

        // The advances themselves are marked off once the payslip exists to
        // point at, and released again if it is taken away.
        static::saved(function (self $payment) {
            $payment->applyAdvanceRecovery();
            $payment->claimAdjustments();
            $payment->answerRequests();
        });

        // Before the row goes, not after. `salary_payment_id` is a foreign
        // key with nullOnDelete, so by the time a `deleted` hook runs the
        // database has already cleared the link and there is nothing left
        // to find. That is harmless for the adjustments, which only needed
        // the link cleared — but a request also has to go back to pending,
        // and no foreign key can do that.
        static::deleting(function (self $payment) {
            $payment->reopenRequests();
        });

        static::deleted(function (self $payment) {
            $payment->releaseAdvanceRecovery();
            $payment->releaseAdjustments();
        });
    }

    /**
     * How much of this employee's outstanding advances this payslip absorbs.
     *
     * Never more than the pay itself: an advance bigger than a month's wage
     * is recovered over as many months as it takes rather than handing
     * someone a negative payslip.
     */
    public function advanceToRecover(): float
    {
        if (! $this->user_id) {
            return 0.0;
        }

        $available = (float) $this->base_amount
            + (float) $this->bonus
            - (float) $this->deduction;

        if ($available <= 0) {
            return 0.0;
        }

        // This payslip's own recoveries are set aside, so re-saving it
        // recomputes from the same starting point rather than compounding.
        $outstanding = StaffAdvance::outstandingFor($this->user_id, $this->id);

        return round(min($outstanding, $available), 2);
    }

    /** Writes this payslip's recovery against the advances, oldest first. */
    public function applyAdvanceRecovery(): void
    {
        $this->recoveries()->delete();

        $remaining = (float) $this->advance_deduction;

        if ($remaining <= 0) {
            return;
        }

        $advances = StaffAdvance::where('user_id', $this->user_id)
            ->outstanding()
            ->get();

        foreach ($advances as $advance) {
            if ($remaining <= 0) {
                break;
            }

            $take = round(min($advance->outstanding, $remaining), 2);

            if ($take <= 0) {
                continue;
            }

            $this->recoveries()->create([
                'staff_advance_id' => $advance->id,
                'amount' => $take,
            ]);

            $remaining = round($remaining - $take, 2);
        }
    }

    /** Hands back whatever this payslip had taken. */
    public function releaseAdvanceRecovery(): void
    {
        $this->recoveries()->delete();
    }

    /**
     * Marks this month's rewards and penalties as settled by this payslip.
     *
     * A link, never arithmetic. The bonus and deduction stored here are
     * whatever was on screen when the button was pressed — the owner may
     * have changed them, and a server that quietly re-added the
     * adjustments on top would store a figure he never saw. That is the
     * bug this shop spent 2026-08-17 finding, in a different field.
     *
     * What this does is stop the same reward being offered again next
     * month, and it says which payslip answered for it.
     */
    public function claimAdjustments(): void
    {
        if (! $this->user_id || ! $this->period_start) {
            return;
        }

        [$from, $until] = Jalali::monthRangeFor($this->period_start->copy());

        StaffAdjustment::where('user_id', $this->user_id)
            ->whereBetween('occurred_on', [$from, $until])
            ->where(function ($q) {
                $q->whereNull('salary_payment_id')->orWhere('salary_payment_id', $this->id);
            })
            ->update(['salary_payment_id' => $this->id]);
    }

    /** Hands them back, unsettled, if the payslip is taken away. */
    public function releaseAdjustments(): void
    {
        StaffAdjustment::where('salary_payment_id', $this->id)
            ->update(['salary_payment_id' => null]);
    }

    /**
     * Answers whoever asked to be paid for this month.
     *
     * Paying is what approval means. There is no approve button anywhere:
     * one would write a wage nobody had looked at, and every figure on a
     * payslip — the advance, the month's rewards, the account it leaves —
     * has to be seen before the money moves.
     */
    public function answerRequests(): void
    {
        if (! $this->user_id || ! $this->period_start || ! self::requestsTableExists()) {
            return;
        }

        SalaryPaymentRequest::where('user_id', $this->user_id)
            ->whereDate('period_start', $this->period_start->toDateString())
            ->pending()
            ->update([
                'status' => SalaryPaymentRequest::PAID,
                'salary_payment_id' => $this->id,
                'decided_at' => now(),
            ]);
    }

    /**
     * Whether the requests feature is actually installed here.
     *
     * Paying somebody their wages must not fail because a different
     * feature's migration has not been run yet. On 1405/05/29 this shop's
     * first payslip in its history — 150,000,000 Rial, correctly written,
     * correctly posted to the bank — ended in a red error because this
     * hook reached for a table that did not exist. Nothing was lost, but
     * only by luck: the throw happened after the payment and its posting
     * were already saved.
     *
     * A wage is the most important thing this system writes. It does not
     * get to depend on a table that answers a convenience.
     *
     * Cached for the process: this is called on every payslip save and the
     * answer cannot change mid-request.
     */
    private static ?bool $hasRequestsTable = null;

    private static function requestsTableExists(): bool
    {
        return self::$hasRequestsTable ??= Schema::hasTable('salary_payment_requests');
    }

    /** A wage taken back leaves the person asking again. */
    public function reopenRequests(): void
    {
        if (! self::requestsTableExists()) {
            return;
        }

        SalaryPaymentRequest::where('salary_payment_id', $this->id)
            ->update([
                'status' => SalaryPaymentRequest::PENDING,
                'salary_payment_id' => null,
                'decided_at' => null,
            ]);
    }

    public function paymentRequests()
    {
        return $this->hasMany(SalaryPaymentRequest::class);
    }

    public function adjustments()
    {
        return $this->hasMany(StaffAdjustment::class);
    }

    public function recoveries()
    {
        return $this->hasMany(SalaryAdvanceRecovery::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function scopePaid($query)
    {
        return $query->whereNotNull('paid_on');
    }

    public function scopeUnpaid($query)
    {
        return $query->whereNull('paid_on');
    }

    public function getIsPaidAttribute(): bool
    {
        return $this->paid_on !== null;
    }

    // ------------------------------------------------- bank posting

    /** Only a salary that has actually been paid moves money. */
    public function bankPostingAccountId(): ?int
    {
        return $this->paid_on === null ? null : $this->bank_account_id;
    }

    public function bankPostingAmount(): float
    {
        return (float) $this->net_amount;
    }

    public function bankPostingReason(): string
    {
        return 'salary';
    }

    public function bankPostingDate()
    {
        return $this->paid_on ?? now();
    }
}
