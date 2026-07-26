<?php

namespace App\Models;

use App\Support\Ledger;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A partner's stake in the bakery, measured in dang. Six dang is the whole
 * traditionally, but nothing here assumes that — a holder's cut is always
 * their dang divided by the total dang on the books.
 */
class BakeryShare extends Model
{
    /** The traditional whole, used only as the default when seeding. */
    public const FULL_DANG = 6;

    protected $fillable = [
        'name',
        'user_id',
        'phone',
        'dang',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'dang' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function settlements()
    {
        return $this->hasMany(ShareSettlement::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Total dang held by active partners — the denominator for every split. */
    public static function totalDang(): float
    {
        return round((float) static::active()->sum('dang'), 3);
    }

    /**
     * This holder's fraction of the whole, 0-1. Derived from the live total
     * so adding a partner re-balances everyone at once.
     */
    public function getShareFractionAttribute(): float
    {
        $total = self::totalDang();

        // Deliberately not rounded: rounding the fraction before multiplying
        // makes the individual cuts fail to add back up to the profit.
        return $total > 0 ? (float) $this->dang / $total : 0.0;
    }

    public function getSharePercentAttribute(): float
    {
        return round($this->share_fraction * 100, 2);
    }

    /** e.g. "۲ دانگ از ۶" */
    public function getDangLabelAttribute(): string
    {
        $dang = rtrim(rtrim(number_format((float) $this->dang, 3), '0'), '.');
        $total = rtrim(rtrim(number_format(self::totalDang(), 3), '0'), '.');

        return $dang.' دانگ از '.$total;
    }

    /** This holder's cut of the profit over a period. */
    public function profitShare(Carbon $from, Carbon $to): float
    {
        return round(Ledger::profit($from, $to) * $this->share_fraction, 2);
    }

    /** What has already been paid out to this holder for a period. */
    public function settledFor(Carbon $from, Carbon $to): float
    {
        return round((float) $this->settlements()
            ->whereNotNull('paid_on')
            ->where('period_start', '>=', $from->toDateString())
            ->where('period_end', '<=', $to->toDateString())
            ->sum('amount'), 2);
    }

    /**
     * The full split for a period: every active partner, their cut, what
     * they have been paid and what is still owed.
     */
    public static function splitFor(Carbon $from, Carbon $to): array
    {
        $profit = Ledger::profit($from, $to);
        $holders = static::active()->orderByDesc('dang')->get();

        // Each cut is rounded to the currency, which can leave the total a
        // few rial short of the profit. The residual goes to the largest
        // holder so the split always adds back up exactly.
        $cuts = $holders->map(fn (self $h) => round($profit * $h->share_fraction, 2))->all();

        if ($cuts !== []) {
            $cuts[0] = round($cuts[0] + ($profit - array_sum($cuts)), 2);
        }

        return [
            'profit' => $profit,
            'profit_formatted' => Money::format($profit),
            'total_dang' => self::totalDang(),
            'holders' => $holders->map(function (self $holder, int $index) use ($from, $to, $cuts) {
                $cut = $cuts[$index];
                $paid = $holder->settledFor($from, $to);

                return [
                    'id' => $holder->id,
                    'name' => $holder->name,
                    'dang' => (float) $holder->dang,
                    'dang_label' => $holder->dang_label,
                    'share_percent' => $holder->share_percent,
                    'amount' => $cut,
                    'amount_formatted' => Money::format($cut),
                    'paid' => $paid,
                    'paid_formatted' => Money::format($paid),
                    'remaining' => round($cut - $paid, 2),
                    'remaining_formatted' => Money::format($cut - $paid),
                ];
            })->values()->all(),
        ];
    }
}
