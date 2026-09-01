<?php

namespace App\Support;

use App\Models\FlourAllocation;

/**
 * The shop's flour entitlement as a running balance.
 *
 * **Quota does not expire.** What a period does not lift rolls forward
 * into the next one, and what a month does not lift rolls into the month
 * after — «انتقال میشن پشت سر هم», in the owner's words. So a period is
 * not a box that has to be emptied by a date; it is one more credit into
 * an account the shop draws on.
 *
 * This system used to treat each period as closed, and was wrong in both
 * directions at once: it warned that an unlifted period was about to be
 * lost when nothing was at risk, and it would have called a period that
 * drew on carried-forward quota «over quota» when the shop was well
 * inside its entitlement.
 *
 * «Used» is flour that went into production, which is how every other
 * quota figure here is counted — not flour collected from the mill,
 * which the system does not separately record.
 */
class FlourQuota
{
    /**
     * Entitlement, usage and the balance between them, in kilograms.
     *
     * Only periods that have actually started count: a period beginning
     * next week is not money in the account yet.
     *
     * @return array{allocated: float, used: float, remaining: float}
     */
    public static function balance(): array
    {
        $allocated = 0.0;
        $used = 0.0;

        foreach (FlourAllocation::with('periods')->get() as $allocation) {
            $counted = false;

            foreach ($allocation->periods as $period) {
                if ($period->starts_on === null || $period->starts_on->gt(today())) {
                    continue;
                }

                $allocated += (float) $period->allocated_kg;
                $used += (float) $period->used_kg;
                $counted = true;
            }

            // A month's periods are cut from `available_kg`, which is the
            // new grant *plus* whatever was typed into `carryover_bags`.
            // That field is a hand-kept copy of what the arithmetic here
            // already gives, so counting both would count the previous
            // month's leftover twice. It has been zero in every record so
            // far; this makes sure it stays harmless if it ever is not.
            if ($counted) {
                $allocated -= (float) $allocation->carryover_kg;
            }
        }

        return [
            'allocated' => round($allocated, 3),
            'used' => round($used, 3),
            'remaining' => round($allocated - $used, 3),
        ];
    }

    /** What the shop may still take, in kilograms. Negative means overdrawn. */
    public static function remainingKg(): float
    {
        return self::balance()['remaining'];
    }

    /**
     * The same figure in sacks, which is the unit the store is counted in.
     *
     * Null when no bag weight is configured, rather than a division by
     * zero dressed up as a quantity.
     */
    public static function remainingBags(): ?float
    {
        $bag = DoughFormula::fromBakery()->bagWeightKg;

        return $bag > 0 ? round(self::remainingKg() / $bag, 1) : null;
    }
}
