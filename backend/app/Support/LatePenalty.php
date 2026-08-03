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

    /**
     * The four tariff settings, read in one go.
     *
     * Every figure below comes from the same Bakery row, so reading them one
     * accessor at a time meant a query per value — and totalFor() charging a
     * month of late days paid that cost once per day. Reading fresh on each
     * call keeps a settings change visible immediately, as before.
     */
    private static function settings(): array
    {
        $bakery = Bakery::first();

        return [
            'free_days' => (int) ($bakery?->late_free_days ?: self::DEFAULT_FREE_DAYS),
            'tier1_last_day' => (int) ($bakery?->late_tier1_last_day ?: self::DEFAULT_TIER1_LAST_DAY),
            'tier1_amount' => $bakery?->late_tier1_amount === null
                ? (float) self::DEFAULT_TIER1_AMOUNT
                : (float) $bakery->late_tier1_amount,
            'tier2_amount' => $bakery?->late_tier2_amount === null
                ? (float) self::DEFAULT_TIER2_AMOUNT
                : (float) $bakery->late_tier2_amount,
        ];
    }

    public static function freeDays(): int
    {
        return self::settings()['free_days'];
    }

    public static function tier1LastDay(): int
    {
        return self::settings()['tier1_last_day'];
    }

    public static function tier1Amount(): float
    {
        return self::settings()['tier1_amount'];
    }

    public static function tier2Amount(): float
    {
        return self::settings()['tier2_amount'];
    }

    /**
     * What the nth late day of the month costs.
     *
     * @param  int  $sequence  1 for the first late day of the month, and so on.
     */
    public static function amountFor(int $sequence): float
    {
        return self::amountWith($sequence, self::settings());
    }

    /** The running total for a month, given how many late days there were. */
    public static function totalFor(int $lateDays): float
    {
        $settings = self::settings();
        $total = 0.0;

        for ($i = 1; $i <= $lateDays; $i++) {
            $total += self::amountWith($i, $settings);
        }

        return round($total, 2);
    }

    /** The tariff applied to one day, against an already-read settings set. */
    private static function amountWith(int $sequence, array $settings): float
    {
        if ($sequence <= 0 || $sequence <= $settings['free_days']) {
            return 0.0;
        }

        return $sequence <= $settings['tier1_last_day']
            ? $settings['tier1_amount']
            : $settings['tier2_amount'];
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
        ['free_days' => $free, 'tier1_last_day' => $tier1Last,
            'tier1_amount' => $tier1, 'tier2_amount' => $tier2] = self::settings();

        return [
            'free_days' => $free,
            'tier1_last_day' => $tier1Last,
            'tier1_amount' => Money::convert($tier1),
            'tier1_amount_formatted' => Money::format($tier1),
            'tier2_amount' => Money::convert($tier2),
            'tier2_amount_formatted' => Money::format($tier2),
            'summary' => 'تا '.$free.' روز تأخیر در ماه فقط اخطار است. '
                .'از روز '.($free + 1).' تا '.$tier1Last
                .' روزی '.Money::format($tier1).' و '
                .'پس از آن روزی '.Money::format($tier2).' کسر می‌شود.',
        ];
    }
}
