<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Model;

/**
 * One reward or one penalty, written down on the day it was earned.
 *
 * The payslip's «پاداش» and «کسورات» boxes are typed at payday, which is
 * the end of a long month. Nobody remembers who was late on the 12th. This
 * is the same two figures, recorded when they happen and with the reason
 * attached, so the month's total is arrived at rather than recalled.
 *
 * It is not itself money moving. Nothing leaves an account here — the
 * payslip does that, once, for the net. This only says what the month
 * came to.
 */
class StaffAdjustment extends Model
{
    use BelongsToBakery, RecordsAudit;

    public const REWARD = 'reward';

    public const PENALTY = 'penalty';

    /** A sum, straight out. */
    public const BY_AMOUNT = 'amount';

    /** Days of pay, priced from this person's own monthly wage. */
    public const BY_DAYS = 'days';

    /** On the record, worth nothing. Saying it was the point. */
    public const BY_NOTE = 'note';

    /**
     * Days in a month for pricing a day of pay.
     *
     * Thirty, not the count of days actually worked: a wage here is agreed
     * as a monthly figure and is not docked for the shop being shut. Using
     * working days would make the same half-day cost more in a short month,
     * which is not what anyone agreed to.
     */
    public const DAYS_IN_MONTH = 30;

    protected $fillable = [
        'user_id',
        'recorded_by',
        'kind',
        'basis',
        'amount',
        'days',
        'occurred_on',
        'reason',
        'salary_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'amount' => 'decimal:2',
            'days' => 'decimal:2',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function salaryPayment()
    {
        return $this->belongsTo(SalaryPayment::class);
    }

    /**
     * What this is worth in money, in Toman.
     *
     * A days-based entry is priced here rather than at the moment it was
     * recorded, so a wage agreed later still prices it correctly — and a
     * note-based one is worth nothing by design, which is different from
     * an amount that happens to be zero.
     */
    public function getValueAttribute(): float
    {
        return match ($this->basis) {
            self::BY_AMOUNT => round((float) $this->amount, 2),
            self::BY_DAYS => round((float) $this->days * $this->dailyWage(), 2),
            default => 0.0,
        };
    }

    public function dailyWage(): float
    {
        $monthly = (float) ($this->user?->monthly_salary ?? 0);

        return $monthly <= 0 ? 0.0 : round($monthly / self::DAYS_IN_MONTH, 2);
    }

    public function getIsRewardAttribute(): bool
    {
        return $this->kind === self::REWARD;
    }

    /** Nothing to pay or dock — it is here so it is not forgotten. */
    public function getIsNoteOnlyAttribute(): bool
    {
        return $this->basis === self::BY_NOTE;
    }

    public function getKindLabelAttribute(): string
    {
        return $this->is_reward ? 'تشویقی' : 'تنبیهی';
    }

    /** How it reads on a list: «نیم روز» or a sum or nothing at all. */
    public function getBasisLabelAttribute(): string
    {
        return match ($this->basis) {
            self::BY_DAYS => (float) $this->days === 0.5
                ? 'نیم روز'
                : rtrim(rtrim(number_format((float) $this->days, 2), '0'), '.').' روز',
            self::BY_NOTE => 'بدون کسر',
            default => '',
        };
    }

    public function scopeRewards($query)
    {
        return $query->where('kind', self::REWARD);
    }

    public function scopePenalties($query)
    {
        return $query->where('kind', self::PENALTY);
    }

    /** Not yet carried onto any payslip. */
    public function scopeUnsettled($query)
    {
        return $query->whereNull('salary_payment_id');
    }

    /**
     * What one person's month comes to, as a reward total and a penalty
     * total kept apart.
     *
     * Apart, because they land in two different boxes on the payslip and
     * netting them off would hide both. Someone who earned a reward and
     * took a penalty in the same month is owed the sight of both.
     */
    public static function monthFor(int $userId, $from, $until): array
    {
        $rows = static::with('user:id,monthly_salary')
            ->where('user_id', $userId)
            ->unsettled()
            ->whereBetween('occurred_on', [$from, $until])
            ->get();

        return [
            'reward' => round($rows->where('kind', self::REWARD)->sum('value'), 2),
            'penalty' => round($rows->where('kind', self::PENALTY)->sum('value'), 2),
            'count' => $rows->count(),
        ];
    }
}
