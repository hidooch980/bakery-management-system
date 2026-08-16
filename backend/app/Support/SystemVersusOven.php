<?php

namespace App\Support;

use App\Models\BankTransaction;
use App\Models\ChaneEntry;
use App\Models\Sale;
use Carbon\Carbon;

/**
 * What the oven made against what the national system saw.
 *
 * Bread sold through the card reader registers with سامانه نانینو; bread
 * sold for cash, on credit, taken home or given away does not. The shop's
 * flour quota follows what the system sees, so the difference between the
 * two is not an accounting curiosity — it is the part of a day's baking
 * that earns the shop nothing towards next month's flour.
 *
 * A debt settled later on the card registers then, which is why the
 * reckoning counts collections and not only sales: bread that went out on
 * credit in Mordad and was paid for by card in Shahrivar was invisible on
 * the day and is not invisible now.
 */
class SystemVersusOven
{
    /** Payment types the national system never sees. */
    public const UNSEEN = ['cash', 'credit', 'schools', 'home', 'charity'];

    public function __construct(
        private Carbon $from,
        private Carbon $to,
    ) {}

    public static function forMonth(?Carbon $at = null): self
    {
        [$from, $to] = Jalali::monthRangeFor($at ?? now());

        return new self($from, $to);
    }

    public static function forDays(int $days): self
    {
        return new self(now()->copy()->subDays($days - 1)->startOfDay(), now()->endOfDay());
    }

    /** Loaves shaped in the period — everything the oven actually made. */
    public function baked(): int
    {
        return (int) ChaneEntry::whereBetween('created_at', [
            $this->from->copy()->startOfDay(),
            $this->to->copy()->endOfDay(),
        ])->sum('chane_count');
    }

    /**
     * Loaves the national system saw: sold through the card reader.
     *
     * Counted in loaves rather than money so it sits beside the baked
     * figure without a price in between — a price change mid-period would
     * otherwise move a number that is about bread.
     */
    public function seenBySystem(): int
    {
        return (int) $this->sales()->where('payment_type', 'card')->sum('bread_count');
    }

    /**
     * Debts collected on the card in the period, in Rial.
     *
     * These register with the system when they are collected, not when the
     * bread went out — often a different month, sometimes outside this
     * window entirely, which is why they are reported apart from the loaf
     * count rather than folded into it.
     */
    public function collectedOnCard(): float
    {
        return (float) BankTransaction::where('reason', 'settlement')
            ->where('direction', 'in')
            ->whereBetween('occurred_on', [
                $this->from->copy()->startOfDay(),
                $this->to->copy()->endOfDay(),
            ])
            ->where('note', 'like', '%کارتخوان%')
            ->sum('amount');
    }

    /** What the system never saw, split by where it went. */
    public function unseen(): array
    {
        $rows = $this->sales()
            ->whereIn('payment_type', self::UNSEEN)
            ->selectRaw('payment_type, sum(bread_count) loaves')
            ->groupBy('payment_type')
            ->pluck('loaves', 'payment_type');

        $out = [];

        foreach (self::UNSEEN as $type) {
            $loaves = (int) ($rows[$type] ?? 0);

            if ($loaves > 0) {
                $out[$type] = $loaves;
            }
        }

        return $out;
    }

    /**
     * Baking the system did not see, including what was never sold.
     *
     * Taken from the baked figure rather than by adding the unseen types
     * up, so a shortfall — bread shaped and not accounted for at all —
     * lands here instead of vanishing. It is the honest reading: the oven
     * made this much, the system saw that much, and the rest is the gap
     * whatever its reason.
     */
    public function gap(): int
    {
        return max(0, $this->baked() - $this->seenBySystem());
    }

    public function shareSeen(): float
    {
        $baked = $this->baked();

        return $baked > 0 ? round($this->seenBySystem() / $baked * 100, 1) : 0.0;
    }

    public function periodLabel(): string
    {
        return AppCalendar::date($this->from).' تا '.AppCalendar::date($this->to);
    }

    private function sales()
    {
        return Sale::whereBetween('created_at', [
            $this->from->copy()->startOfDay(),
            $this->to->copy()->endOfDay(),
        ]);
    }
}
