<?php

namespace App\Support;

use App\Models\Bakery;

/**
 * The escalating tariff for starting work late.
 *
 * The first few late days of a month cost nothing but a warning; after that
 * a daily amount applies, rising again once the run gets long. The count is
 * of late *days*, not late ticks — a day where both shaping and baking were
 * late is still one late day and is charged once.
 *
 * All amounts are in stored Toman, like every other figure in the system.
 */
class LatePenalty
{
    public const DEFAULT_FREE_DAYS = 3;
    public const DEFAULT_TIER1_LAST_DAY = 7;
    public const DEFAULT_TIER1_AMOUNT = 200_000;   // 2,000,000 Rial
    public const DEFAULT_TIER2_AMOUNT = 500_000;   // 5,000,000 Rial

    public static function freeDays(): int
    {
        return (int) (Bakery::first()?->late_free_days ?: self::DEFAULT_FREE_DAYS);
    }

    public static function tier1LastDay(): int
    {
        return (int) (Bakery::first()?->late_tier1_last_day ?: self::DEFAULT_TIER1_LAST_DAY);
    }

    public static function tier1Amount(): float
    {
        $value = Bakery::first()?->late_tier1_amount;

        return $value === null ? self::DEFAULT_TIER1_AMOUNT : (float) $value;
    }

    public static function tier2Amount(): float
    {
        $value = Bakery::first()?->late_tier2_amount;

        return $value === null ? self::DEFAULT_TIER2_AMOUNT : (float) $value;
    }

    /**
     * What the nth late day of the month costs.
     *
     * @param  int  $sequence  1 for the first late day of the month, and so on.
     */
    public static function amountFor(int $sequence): float
    {
        if ($sequence <= 0) {
            return 0.0;
        }

        if ($sequence <= self::freeDays()) {
            return 0.0;
        }

        return $sequence <= self::tier1LastDay()
            ? self::tier1Amount()
            : self::tier2Amount();
    }

    /** The running total for a month, given how many late days there were. */
    public static function totalFor(int $lateDays): float
    {
        $total = 0.0;

        for ($i = 1; $i <= $lateDays; $i++) {
            $total += self::amountFor($i);
        }

        return round($total, 2);
    }

    /** Explains what this particular late day costs, or that it is free. */
    public static function describe(int $sequence): string
    {
        $amount = self::amountFor($sequence);
        $free = self::freeDays();

        if ($amount <= 0) {
            $remaining = $free - $sequence;

            return $remaining > 0
                ? "این {$sequence}اُمین تأخیر این ماه است. تا {$free} تأخیر فقط اخطار است"
                    ." — {$remaining} اخطار دیگر باقی مانده."
                : "این {$sequence}اُمین تأخیر این ماه است و آخرین اخطار بدون جریمه.";
        }

        return "این {$sequence}اُمین تأخیر این ماه است و "
            .Money::format($amount).' کسر حقوق دارد.';
    }

    /** The tariff itself, for showing staff the rules up front. */
    public static function tariff(): array
    {
        return [
            'free_days' => self::freeDays(),
            'tier1_last_day' => self::tier1LastDay(),
            'tier1_amount' => Money::convert(self::tier1Amount()),
            'tier1_amount_formatted' => Money::format(self::tier1Amount()),
            'tier2_amount' => Money::convert(self::tier2Amount()),
            'tier2_amount_formatted' => Money::format(self::tier2Amount()),
            'summary' => 'تا '.self::freeDays().' روز تأخیر در ماه فقط اخطار است. '
                .'از روز '.(self::freeDays() + 1).' تا '.self::tier1LastDay()
                .' روزی '.Money::format(self::tier1Amount()).' و '
                .'پس از آن روزی '.Money::format(self::tier2Amount()).' کسر می‌شود.',
        ];
    }
}
