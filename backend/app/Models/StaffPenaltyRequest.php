<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
use App\Support\StaffPenaltySms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A seller asking for a penalty on someone.
 *
 * Not a [[StaffAdjustment]] until the manager approves it: an adjustment is
 * counted on the payslip, and a request sitting in that table would dock
 * pay before anyone had said yes. Approving creates exactly one adjustment
 * and keeps a pointer to it; cancelling later waives that adjustment
 * rather than deleting it, so the amount and the decision both stay.
 */
class StaffPenaltyRequest extends Model
{
    use BelongsToBakery, RecordsAudit;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const FINE = 'fine';

    public const WARNING = 'warning';

    public const STATUS_LABELS = [
        self::PENDING => 'در انتظار تأیید',
        self::APPROVED => 'تأیید شد',
        self::REJECTED => 'رد شد',
    ];

    public const TYPE_LABELS = [
        self::FINE => 'جریمه (کسر از حقوق)',
        self::WARNING => 'اخطار',
    ];

    public const REASONS = [
        'late' => 'تأخیر',
        'absence' => 'غیبت',
        'spoiled_bread' => 'خرابی نان',
        'misconduct' => 'بی‌نظمی',
        // Filed by a seller-warning rule.
        'debt' => 'بدهی بیش از سقف',
        'late_settlement' => 'تسویه دیرهنگام',
        'till_shortfall' => 'کسری صندوق',
        'rule' => 'قانون فروشنده',
        // The complaint list buyers pick from in nanino's buyer app
        // (nanino.ir, «برنامه کاربردی خریدار»). Most can only be seen by
        // a person, so they are reasons to choose, not rules to check.
        'chane_weight' => 'عدم رعایت وزن استاندارد چانه خمیر',
        'hygiene' => 'عدم رعایت اصول بهداشتی',
        'bread_quality' => 'پایین بودن کیفیت نان',
        'fake_transaction' => 'ثبت تراکنش غیر واقعی',
        'off_queue' => 'عرضه بیش از حد نان خارج از صف',
        'bran_mismatch' => 'عدم تناسب میزان سبوس آرد با نوع نان',
        'forced_sesame' => 'اجبار مشتری به خرید نان کنجدی',
        'customer_conduct' => 'عدم برخورد صحیح با مشتری',
        'shift_hours' => 'عدم رعایت ساعت شیفت کاری',
        'other' => 'سایر',
    ];

    protected $attributes = ['status' => self::PENDING];

    protected $fillable = [
        'user_id', 'requested_by', 'seller_warning_rule_id', 'type', 'reason', 'amount', 'note', 'occurred_on',
        'status', 'decided_by', 'decided_at', 'decision_note', 'staff_adjustment_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_on' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function rule()
    {
        return $this->belongsTo(SellerWarningRule::class, 'seller_warning_rule_id');
    }

    /** Who asked: a person, or the rule that filed it. */
    public function getRequesterNameAttribute(): ?string
    {
        if ($this->seller_warning_rule_id !== null) {
            return 'قانون: '.($this->rule?->name ?? 'حذف‌شده');
        }

        return $this->requestedBy?->name;
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function adjustment()
    {
        return $this->belongsTo(StaffAdjustment::class, 'staff_adjustment_id');
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

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function getReasonLabelAttribute(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }

    /** Cancelled after approval: the adjustment exists but is waived. */
    public function getIsCancelledAttribute(): bool
    {
        return $this->status === self::APPROVED && $this->adjustment?->isWaived() === true;
    }

    /** Was this person recorded late on the same day? Only a warning. */
    public function lateThatDay(): ?int
    {
        $start = WorkStart::query()
            ->where('user_id', $this->user_id)
            ->whereDate('date', $this->occurred_on)
            ->where('is_late', true)
            ->first();

        return $start ? (int) $start->late_minutes : null;
    }

    /**
     * Approve: write the one adjustment the payslip will count.
     *
     * The manager may change the amount of a fine; a warning stays worth
     * nothing. Guarded by a row lock so a double tap cannot write two.
     */
    public function approve(User $by, ?float $amount = null, ?string $note = null): StaffAdjustment
    {
        return DB::transaction(function () use ($by, $amount, $note) {
            $fresh = self::query()->lockForUpdate()->findOrFail($this->id);
            if (! $fresh->is_pending) {
                throw new \DomainException('به این درخواست قبلاً پاسخ داده شده است.');
            }

            $isFine = $this->type === self::FINE;
            $final = $isFine ? ($amount ?? (float) $this->amount) : null;

            $adjustment = StaffAdjustment::create([
                'user_id' => $this->user_id,
                'recorded_by' => $by->id,
                'kind' => StaffAdjustment::PENALTY,
                'basis' => $isFine ? StaffAdjustment::BY_AMOUNT : StaffAdjustment::BY_NOTE,
                'amount' => $final,
                'occurred_on' => $this->occurred_on,
                'reason' => trim($this->reason_label.' — '.($this->note ?? ''), ' —'),
            ]);

            $this->update([
                'status' => self::APPROVED,
                'amount' => $isFine ? $final : null,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'staff_adjustment_id' => $adjustment->id,
            ]);

            DB::afterCommit(fn () => StaffPenaltySms::approved($this, $by));

            return $adjustment;
        });
    }

    public function reject(User $by, ?string $note = null): void
    {
        DB::transaction(function () use ($by, $note) {
            $fresh = self::query()->lockForUpdate()->findOrFail($this->id);
            if (! $fresh->is_pending) {
                throw new \DomainException('به این درخواست قبلاً پاسخ داده شده است.');
            }
            $this->update([
                'status' => self::REJECTED,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);
        });
    }

    /** Cancel an approved penalty by waiving it. Never deleted. */
    public function cancel(User $by): void
    {
        $adjustment = $this->adjustment;
        if ($this->status !== self::APPROVED || $adjustment === null) {
            throw new \DomainException('فقط تنبیهِ تأییدشده لغو می‌شود.');
        }
        if ($adjustment->salary_payment_id !== null) {
            throw new \DomainException('این تنبیه در فیش حقوقِ پرداخت‌شده آمده و دیگر لغو نمی‌شود.');
        }
        if (! $adjustment->isWaived()) {
            $adjustment->update(['waived_at' => now(), 'waived_by' => $by->id]);
            StaffPenaltySms::cancelled($this, $by);
        }
    }

    public function auditSubject(): ?string
    {
        return trim('درخواست تنبیه '.($this->user?->name ?? ''));
    }
}
