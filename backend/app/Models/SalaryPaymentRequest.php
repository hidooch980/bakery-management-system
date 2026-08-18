<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Support\Jalali;
use Illuminate\Database\Eloquent\Model;

/**
 * A member of staff asking to be paid for the month.
 *
 * Deliberately not a [[SalaryPayment]] until it is answered: that record
 * takes money out of an account, recovers advances, and settles the
 * month's rewards and penalties. A request that lived in the same table
 * would do all three before anyone had said yes.
 *
 * It carries no amount. The wage is what was agreed, less what has been
 * drawn against it — not the employee's to propose. Asking him for a
 * figure would start a negotiation over a number the system already knows
 * and set him up to be told he was wrong.
 *
 * The payment is the answer. There is no approve button that writes a wage
 * nobody looked at: paying the person for that month marks their request
 * answered and links the two.
 */
class SalaryPaymentRequest extends Model
{
    use BelongsToBakery;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::PENDING => 'در انتظار پرداخت',
        self::PAID => 'پرداخت شد',
        self::REJECTED => 'رد شد',
    ];

    /**
     * The column defaults to pending, but a database default only applies
     * at insert — a model just created still has no status in memory, and
     * the response describing it reads back a blank. The advance request
     * learned this the same way.
     */
    protected $attributes = [
        'status' => self::PENDING,
    ];

    protected $fillable = [
        'user_id',
        'period_start',
        'period_label',
        'status',
        'note',
        'decided_by',
        'decided_at',
        'decision_note',
        'salary_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $request) {
            $request->period_label ??= Jalali::monthLabel($request->period_start) ?? '';
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function salaryPayment()
    {
        return $this->belongsTo(SalaryPayment::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status === self::PENDING;
    }

    /** How long it has gone unanswered, for the shop to be asked about it. */
    public function getDaysWaitingAttribute(): int
    {
        return $this->is_pending
            ? (int) $this->created_at->startOfDay()->diffInDays(now()->startOfDay())
            : 0;
    }
}
