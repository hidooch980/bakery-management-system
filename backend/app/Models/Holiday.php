<?php

namespace App\Models;

use App\Support\AppCalendar;
use App\Support\Jalali;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Holiday extends Model
{
    public const TYPES = [
        'official' => 'تعطیل رسمی',
        'religious' => 'مناسبت مذهبی',
        'shop' => 'تعطیلی نانوایی',
    ];

    protected $fillable = ['date', 'title', 'type', 'note'];

    protected function casts(): array
    {
        return ['date' => 'date'];
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
