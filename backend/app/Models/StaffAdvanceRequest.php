<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Model;

/**
 * A member of staff asking for pay early.
 *
 * Deliberately not a [[StaffAdvance]] until it is granted: that record
 * means money out of the till, posts to a bank account, and is deducted
 * from the next payslip. A request that sat in the same table would do all
 * three before anyone had said yes.
 */
class StaffAdvanceRequest extends Model
{
    use BelongsToBakery, RecordsAudit;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::PENDING => 'در انتظار پاسخ',
        self::APPROVED => 'تأیید شد',
        self::REJECTED => 'رد شد',
    ];

    /**
     * The column defaults to pending, but a default in the database only
     * applies at insert: a model just created still had no status in
     * memory, so the response describing it read back a blank.
     */
    protected $attributes = [
        'status' => self::PENDING,
    ];

    protected $fillable = [
        'user_id',
        'amount',
        'reason',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
        'staff_advance_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'decided_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function advance()
    {
        return $this->belongsTo(StaffAdvance::class, 'staff_advance_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status === self::PENDING;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * Grant it: hand over the money and record why the advance exists.
     *
     * The advance carries the request's own amount, not whatever the till
     * happened to hold, and the request keeps a pointer to it so the two
     * can never drift apart.
     */
    public function approve(User $by, ?int $bankAccountId = null, ?string $note = null): StaffAdvance
    {
        $advance = StaffAdvance::create([
            'user_id' => $this->user_id,
            'recorded_by' => $by->id,
            'amount' => $this->amount,
            'paid_on' => now(),
            'bank_account_id' => $bankAccountId,
            'note' => $note ?? $this->reason,
        ]);

        $this->update([
            'status' => self::APPROVED,
            'decided_by' => $by->id,
            'decided_at' => now(),
            'decision_note' => $note,
            'staff_advance_id' => $advance->id,
        ]);

        return $advance;
    }

    public function reject(User $by, ?string $note = null): void
    {
        $this->update([
            'status' => self::REJECTED,
            'decided_by' => $by->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);
    }

    /**
     * How this row names itself in the trail.
     *
     * The log outlives the record: once the row is gone its id points at
     * nothing, and this sentence is all that is left to argue from.
     */
    public function auditSubject(): ?string
    {
        return trim('درخواست علی‌الحساب '.($this->user?->name ?? ''));
    }
}
