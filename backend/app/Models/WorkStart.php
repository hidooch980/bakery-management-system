<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Support\AppCalendar;
use App\Support\CurrentBakery;
use App\Support\Jalali;
use App\Support\LatePenalty;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The daily "we have started" tick for shaping and for baking.
 *
 * Each activity has a deadline; starting after it is flagged as late and
 * carries a salary-deduction warning. Whether a tick was late is decided
 * once, when it is recorded, and stored — changing the deadline setting
 * later must not rewrite history.
 */
class WorkStart extends Model
{
    use BelongsToBakery;

    public const CHANE = 'chane';

    public const BAKING = 'baking';

    public const TYPES = [
        self::CHANE => 'شروع چانه‌گیری',
        self::BAKING => 'شروع پخت',
    ];

    /**
     * Each activity is ticked by the one person who actually does it —
     * the chane gir starts shaping, the seller starts baking. Holding
     * record-work-start is not enough on its own.
     */
    public const RECORDED_BY = [
        self::CHANE => 'chane_gir',
        self::BAKING => 'seller',
    ];

    /** Used when the bakery has not set its own times. */
    public const DEFAULT_DEADLINES = [
        self::CHANE => '05:40',
        self::BAKING => '06:00',
    ];

    protected $fillable = [
        'type',
        'date',
        'started_at',
        'user_id',
        'is_late',
        'late_minutes',
        'late_sequence',
        'penalty_amount',
        'deadline',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'started_at' => 'datetime',
            'is_late' => 'boolean',
            'late_minutes' => 'integer',
            'late_sequence' => 'integer',
            'penalty_amount' => 'decimal:2',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeLate($query)
    {
        return $query->where('is_late', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getStartedAtTimeAttribute(): ?string
    {
        return $this->started_at?->timezone(config('app.timezone'))->format('H:i');
    }

    public function getDateDisplayAttribute(): ?string
    {
        return AppCalendar::date($this->date);
    }

    /** The deadline this activity must start by, from the bakery settings. */
    public static function deadlineFor(string $type): string
    {
        $bakery = CurrentBakery::get();

        $column = $type === self::BAKING
            ? 'baking_start_deadline'
            : 'chane_start_deadline';

        $value = $bakery?->{$column};

        if (empty($value)) {
            return self::DEFAULT_DEADLINES[$type] ?? '06:00';
        }

        // The column is a TIME, which comes back as "05:40:00".
        return substr((string) $value, 0, 5);
    }

    /**
     * Records the tick for today, deciding lateness against the deadline.
     * Returns the existing record if the activity was already started.
     */
    public static function record(string $type, int $userId, ?Carbon $at = null): self
    {
        $now = ($at ?? now())->timezone(config('app.timezone'));
        $date = $now->toDateString();

        $existing = static::where('type', $type)->whereDate('date', $date)->first();

        if ($existing) {
            return $existing;
        }

        $deadline = self::deadlineFor($type);
        [$hour, $minute] = array_map('intval', explode(':', $deadline));

        $cutoff = $now->copy()->setTime($hour, $minute, 0);

        $late = $now->greaterThan($cutoff);

        // The tariff is charged per late day, not per late tick. If this day
        // has already been counted — because the other activity was late too
        // — it must not be charged a second time.
        $sequence = 0;
        $penalty = 0.0;

        if ($late) {
            $sequence = self::lateSequenceFor($now, $date);
            $penalty = self::alreadyCountedLate($date)
                ? 0.0
                : LatePenalty::amountFor($sequence);
        }

        return static::create([
            'type' => $type,
            'date' => $date,
            'started_at' => $now,
            'user_id' => $userId,
            'is_late' => $late,
            'late_minutes' => $late ? $cutoff->diffInMinutes($now) : 0,
            'late_sequence' => $sequence,
            'penalty_amount' => $penalty,
            'deadline' => $deadline,
        ]);
    }

    /** Whether this date was already recorded as late by another activity. */
    private static function alreadyCountedLate(string $date): bool
    {
        return static::whereDate('date', $date)->where('is_late', true)->exists();
    }

    /**
     * Which late day of the Jalali month this is — 1 for the first, and so
     * on. A day already marked late keeps its own number rather than taking
     * a new one.
     */
    public static function lateSequenceFor(Carbon $now, string $date): int
    {
        [$from, $to] = Jalali::monthRangeFor($now);

        $lateDates = static::where('is_late', true)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)
            ->unique();

        // Same day already counted: reuse its position.
        if ($lateDates->contains($date)) {
            return $lateDates->sort()->values()->search($date) + 1;
        }

        return $lateDates->count() + 1;
    }

    /** This Jalali month's late days and what they have cost so far. */
    public static function monthSummary(?Carbon $at = null): array
    {
        $now = ($at ?? now())->timezone(config('app.timezone'));
        [$from, $to] = Jalali::monthRangeFor($now);

        $late = static::where('is_late', true)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $lateDays = $late->pluck('date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)
            ->unique()
            ->count();

        $penalty = (float) $late->sum('penalty_amount');
        $free = LatePenalty::freeDays();

        return [
            'period_label' => AppCalendar::monthLabel($now),
            'late_days' => $lateDays,
            'free_days' => $free,
            'warnings_remaining' => max(0, $free - $lateDays),
            'penalty_total' => Money::convert($penalty),
            'penalty_total_formatted' => Money::format($penalty),
            // What the next late day would cost, so it is known beforehand.
            'next_day_amount_formatted' => Money::format(
                LatePenalty::amountFor($lateDays + 1)
            ),
        ];
    }

    /** The warning shown to staff when a tick was late. */
    public function getWarningAttribute(): ?string
    {
        if (! $this->is_late) {
            return null;
        }

        $message = 'اخطار: '.$this->type_label.' با '.$this->late_minutes
            .' دقیقه تأخیر نسبت به ساعت '.substr((string) $this->deadline, 0, 5)
            .' ثبت شد.';

        if ((float) $this->penalty_amount > 0) {
            return $message.' '.Money::format($this->penalty_amount).' کسر حقوق دارد'
                .' ('.$this->late_sequence.'اُمین تأخیر این ماه).';
        }

        if ($this->late_sequence > 0) {
            return $message.' '.LatePenalty::describe($this->late_sequence);
        }

        return $message;
    }

    /**
     * Today's board for both activities: started or not, late or not, and
     * how long is left before the deadline.
     *
     * On a day the shop is closed there is nothing to be late for, so no
     * deadline is reported at all.
     */
    public static function todayBoard(): array
    {
        $now = now()->timezone(config('app.timezone'));
        $isHoliday = Holiday::whereDate('date', $now->toDateString())->exists();

        $records = static::whereDate('date', $now->toDateString())
            ->with('user:id,name')
            ->get()
            ->keyBy('type');

        $items = [];

        foreach (self::TYPES as $type => $label) {
            $record = $records->get($type);
            $deadline = self::deadlineFor($type);
            [$hour, $minute] = array_map('intval', explode(':', $deadline));
            $cutoff = $now->copy()->setTime($hour, $minute, 0);

            $items[] = [
                'type' => $type,
                'label' => $label,
                'deadline' => $deadline,
                'started' => $record !== null,
                'started_at' => $record?->started_at_time,
                'started_by' => $record?->user?->name,
                'is_late' => (bool) $record?->is_late,
                'late_minutes' => (int) ($record?->late_minutes ?? 0),
                'warning' => $record?->warning,
                'is_holiday' => $isHoliday,
                // Negative once the deadline has passed without a tick.
                'minutes_remaining' => $record || $isHoliday
                    ? null
                    : (int) $now->diffInMinutes($cutoff, false),
                // Nothing recorded and the deadline gone: already late.
                'overdue' => ! $record && ! $isHoliday && $now->greaterThan($cutoff),
            ];
        }

        return [
            'date' => $now->toDateString(),
            'date_display' => AppCalendar::date($now),
            'is_holiday' => $isHoliday,
            'items' => $items,
            // The tariff is shown to every member of staff, not just to
            // whoever was late, so the rules are known in advance.
            'tariff' => LatePenalty::tariff(),
            'month_summary' => self::monthSummary($now),
        ];
    }
}
