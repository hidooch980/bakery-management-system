<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Support\AppCalendar;
use App\Support\Jalali;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Holiday extends Model
{
    use BelongsToBakery;

    public const TYPES = [
        'official' => 'تعطیل رسمی',
        'religious' => 'مناسبت مذهبی',
        'shop' => 'تعطیلی نانوایی',
    ];

    protected $fillable = ['date', 'title', 'type', 'note', 'repeats_monthly', 'repeats_from_id'];

    /** Only a shop closure may repeat; the other types move around. */
    public const REPEATABLE_TYPE = 'shop';

    /** How many months ahead a recurring closure is generated. */
    public const MONTHS_AHEAD = 12;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'repeats_monthly' => 'boolean',
        ];
    }

    /**
     * Before today — not «not in the future».
     *
     * `date` is a date cast, i.e. midnight, so `isPast()` marks *today's*
     * holiday as gone the moment the day begins, while the shop is in fact
     * shut for it. Lived in two places that had to agree and did not have
     * to; it lives here now.
     */
    public function getIsPastAttribute(): bool
    {
        return $this->date !== null && $this->date->lt(today());
    }

    public function generatedOccurrences()
    {
        return $this->hasMany(self::class, 'repeats_from_id');
    }

    public function source()
    {
        return $this->belongsTo(self::class, 'repeats_from_id');
    }

    /** True for a rule the user created, false for a generated occurrence. */
    public function getIsRuleAttribute(): bool
    {
        return $this->repeats_monthly && $this->repeats_from_id === null;
    }

    public function canRepeat(): bool
    {
        return $this->type === self::REPEATABLE_TYPE;
    }

    /**
     * Creates this closure on the same Jalali day of the coming months.
     *
     * Days that do not exist in a given month — the 31st of Mehr, or the 30th
     * of Esfand in a common year — are skipped rather than silently shifted
     * onto a neighbouring day.
     *
     * Returns the number of occurrences created.
     */
    public function generateFutureOccurrences(int $months = self::MONTHS_AHEAD): int
    {
        if (! $this->repeats_monthly || ! $this->canRepeat() || $this->repeats_from_id !== null) {
            return 0;
        }

        [$year, $month, $day] = array_map(
            'intval',
            explode('/', Jalali::format($this->date, 'Y/m/d'))
        );

        $created = 0;

        for ($offset = 1; $offset <= $months; $offset++) {
            $m = $month + $offset;
            $y = $year + (int) floor(($m - 1) / 12);
            $m = (($m - 1) % 12 + 12) % 12 + 1;

            $date = Jalali::parse(sprintf('%04d/%02d/%02d', $y, $m, $day));

            // Jalali::parse returns null for a day that month does not have.
            if ($date === null) {
                continue;
            }

            // Never overwrite a day the admin has already marked.
            if (static::whereDate('date', $date->toDateString())->exists()) {
                continue;
            }

            static::create([
                'date' => $date,
                'title' => $this->title,
                'type' => $this->type,
                'repeats_monthly' => false,
                'repeats_from_id' => $this->id,
            ]);

            $created++;
        }

        return $created;
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getDateDisplayAttribute(): ?string
    {
        return AppCalendar::date($this->date);
    }

    public static function isHoliday(Carbon|string $date): bool
    {
        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return static::whereDate('date', $carbon->toDateString())->exists();
    }

    /** Holidays inside the Jalali month containing the given date. */
    public function scopeInJalaliMonth($query, Carbon $anyDayOfMonth)
    {
        [$start, $end] = Jalali::monthRangeFor($anyDayOfMonth);

        return $query->whereBetween('date', [$start->toDateString(), $end->toDateString()]);
    }

    public function scopeUpcoming($query)
    {
        return $query->whereDate('date', '>=', now()->toDateString());
    }
}
