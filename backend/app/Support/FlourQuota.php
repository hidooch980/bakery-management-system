<?php

namespace App\Support;

use App\Models\FlourAllocation;

/** سهمیهٔ آرد یک حساب پیوسته است؛ ماندهٔ استفاده‌نشده با پایان دوره یا ماه از بین نمی‌رود. */
class FlourQuota
{
    /** سهمیه، مصرف و مانده از آخرین اصلاح دستی؛ فقط اعتبار دوره‌هایی که آغاز شده‌اند. */
    public static function balance(): array
    {
        $allocated = 0.0;
        $used = 0.0;
        foreach (FlourAllocation::with('periods')->orderBy('month_start')->orderBy('id')->get() as $allocation) {
            $periods = $allocation->periods->sortBy('starts_on');
            $started = $periods->filter(fn ($p) => $p->starts_on !== null && $p->starts_on->lte(today()));
            if ($started->isEmpty()) {
                continue;
            }
            $carry = (float) $allocation->carryover_kg;
            if ($carry > 0) {
                // انتقال دستی، اصلاح ماندهٔ ابتدای ماه است؛ جمع قبلی را دوباره اضافه نمی‌کند.
                $allocated = $carry;
                $used = 0.0;
            }
            $stored = (float) $periods->sum('allocated_kg');
            $own = (float) $allocation->total_kg;
            // دوره‌های قدیمی گاهی از سهمیه به‌علاوهٔ انتقال بریده شده‌اند؛ ذخیرهٔ انتقال یک بار شمرده می‌شود.
            $legacy = $carry > 0 && $stored > 0 && abs($stored - $own - $carry) < 0.01;
            foreach ($started as $period) {
                $credit = (float) $period->allocated_kg;
                $allocated += $legacy ? $credit * $own / $stored : $credit;
                $used += (float) $period->used_kg;
            }
        }

        return [
            'allocated' => round($allocated, 3),
            'used' => round($used, 3),
            'remaining' => round($allocated - $used, 3),
        ];
    }

    /** ماندهٔ قابل استفاده؛ عدد منفی کسری ثبت‌شده است. */
    public static function remainingKg(): float
    {
        return self::balance()['remaining'];
    }

    /** مانده به کیسه؛ اندازهٔ نامعلوم به‌صورت صفر ساختگی نمایش داده نمی‌شود. */
    public static function remainingBags(): ?float
    {
        $bag = DoughFormula::fromBakery()->bagWeightKg;

        return $bag > 0 ? round(self::remainingKg() / $bag, 1) : null;
    }
}
