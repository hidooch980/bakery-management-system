<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
use App\Models\Concerns\PostsToBankAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * Money handed to a member of staff before payday.
 *
 * It is not a cost to the shop — it is pay brought forward, so it is never
 * an expense. It leaves the account when it is handed over and comes back
 * as a deduction on the next payslip, oldest advance first, until it is
 * recovered. An advance bigger than one month's pay carries into the month
 * after rather than pushing a payslip below zero.
 *
 * What is still owed is derived from the recoveries on file, never stored,
 * so a payslip that is edited or deleted cannot leave the figure stale.
 */
class StaffAdvance extends Model
{
    use BelongsToBakery, PostsToBankAccount, RecordsAudit;

    protected $fillable = [
        'user_id',
        'recorded_by',
        'amount',
        'paid_on',
        'bank_account_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /** Named after the person, because that is how it will be asked about. */
    public function auditSubject(): ?string
    {
        return trim('علی‌الحساب '.($this->user?->name ?? ''));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function recoveries()
    {
        return $this->hasMany(SalaryAdvanceRecovery::class);
    }

    /** How much of this advance payslips have already taken back. */
    public function getRecoveredAttribute(): float
    {
        return round((float) $this->recoveries()->sum('amount'), 2);
    }

    /** Still to be recovered from pay. */
    public function getOutstandingAttribute(): float
    {
        return round(max(0, (float) $this->amount - $this->recovered), 2);
    }

    public function getIsSettledAttribute(): bool
    {
        return $this->outstanding <= 0;
    }

    /** Advances with something left to recover, oldest first. */
    public function scopeOutstanding($query)
    {
        return $query
            ->whereRaw(
                'amount > (select coalesce(sum(amount), 0) from salary_advance_recoveries'
                .' where staff_advance_id = staff_advances.id)'
            )
            ->orderBy('paid_on')
            ->orderBy('id');
    }

    /** What this employee still owes the shop against past advances. */
    public static function outstandingFor(int $userId, ?int $ignoringSalaryId = null): float
    {
        return round(
            static::query()->where('user_id', $userId)->get()
                ->sum(function (self $advance) use ($ignoringSalaryId) {
                    $recovered = $advance->recoveries()
                        ->when($ignoringSalaryId, fn ($q) => $q->where('salary_payment_id', '!=', $ignoringSalaryId))
                        ->sum('amount');

                    return max(0, (float) $advance->amount - (float) $recovered);
                }),
            2
        );
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
        return 'advance';
    }

    public function bankPostingDate()
    {
        return $this->paid_on ?? now();
    }
}
