<?php

namespace App\Support;

use App\Models\StaffAdjustment;
use App\Models\WorkStart;
use Illuminate\Support\Carbon;

/**
 * The late tariff, applied to wages instead of only described.
 *
 * The tariff was computed, stored on each `WorkStart`, and shown to the
 * person it was about — «جریمه تا اینجا» — while nothing anywhere
 * deducted it. The payslip reads `StaffAdjustment` rows and nothing
 * created one from a late day, so the sentence «کسر می‌شود» was true only
 * if somebody remembered, every month, from figures no screen displayed.
 *
 * This writes that row.
 *
 * One row per person per Jalali month, rewritten from the records each
 * time a day changes, rather than one row per late day. The tariff
 * escalates over the month, so deleting an early late day re-prices every
 * later one; a row per day would have to be renumbered and any missed
 * renumbering is money. A single total recomputed from scratch cannot
 * drift.
 *
 * The amount is the sum of the `penalty_amount` already stored on the
 * records — the same figure the report shows and the same one the baker
 * is shown. Recomputing it from the tariff here would create a second
 * source for one number, and the two would disagree on the day somebody
 * changed the settings.
 */
class LateDeduction
{
    /** Marks the rows this class owns. A person's own rows have null. */
    public const SOURCE = 'late_tariff';

    /**
     * Brings one person's month into line with their late records.
     *
     * Safe to call as often as you like: it computes the total and makes
     * the row match, so a repeat is a no-op.
     */
    public static function sync(int $userId, Carbon $anyDayInMonth): void
    {
        [$from, $until] = Jalali::monthRangeFor($anyDayInMonth->copy());

        // Across shops on purpose, then grouped by shop: this runs from a
        // model event and from the console, and the console has no current
        // bakery to scope to.
        //
        // One group per shop, because a person can work at more than one.
        // Summed together they made one row for the month — proved by
        // running it: 100,000 late at one shop and 250,000 at another
        // became a single 350,000 deduction on the first shop's payslip,
        // with nothing at all on the second's. Harmless while a shop is
        // the only shop; money on the wrong books the day it is not.
        $lateByShop = WorkStart::acrossBakeries()
            ->where('user_id', $userId)
            ->where('is_late', true)
            ->whereBetween('date', [$from->toDateString(), $until->toDateString()])
            ->get(['bakery_id', 'penalty_amount'])
            ->groupBy('bakery_id');

        $existingByShop = StaffAdjustment::acrossBakeries()
            ->where('user_id', $userId)
            ->where('source', self::SOURCE)
            ->whereBetween('occurred_on', [$from->toDateString(), $until->toDateString()])
            ->get()
            ->groupBy('bakery_id');

        // Both sides: a shop whose lateness has gone to nothing still has
        // a row to clear, and it would be missed by walking the late days
        // alone.
        $shopIds = $lateByShop->keys()
            ->merge($existingByShop->keys())
            ->unique();

        foreach ($shopIds as $shopId) {
            self::syncOneShop(
                $userId,
                $shopId === '' ? null : (int) $shopId,
                (float) ($lateByShop->get($shopId)?->sum('penalty_amount') ?? 0),
                $existingByShop->get($shopId)?->first(),
                $from,
                $until,
            );
        }
    }

    /**
     * One person's month at one shop.
     *
     * @param  float  $total  What the tariff says they owe that shop
     */
    private static function syncOneShop(
        int $userId,
        ?int $shopId,
        float $total,
        ?StaffAdjustment $existing,
        Carbon $from,
        Carbon $until,
    ): void {
        // A settled month is history. The payslip has been written and
        // handed over, and moving the figure now would make the paper in
        // somebody's pocket disagree with the books.
        if ($existing?->salary_payment_id !== null) {
            return;
        }

        // Forgiven on purpose. The owner decided, and a nightly job is not
        // the thing that overrules them.
        if ($existing?->isWaived()) {
            return;
        }

        if ($total <= 0) {
            $existing?->delete();

            return;
        }

        $attributes = [
            'kind' => StaffAdjustment::PENALTY,
            'basis' => StaffAdjustment::BY_AMOUNT,
            'source' => self::SOURCE,
            'amount' => $total,
            'days' => null,
            'occurred_on' => $until->toDateString(),
            'reason' => 'کسر تأخیر طبق تعرفه — '.AppCalendar::monthLabel($from),
        ];

        if ($existing) {
            $existing->fill($attributes)->save();

            return;
        }

        $adjustment = new StaffAdjustment($attributes);
        $adjustment->user_id = $userId;
        // Nobody typed this one. `recorded_by` naming a person would say
        // somebody decided it, and the point of a tariff is that nobody
        // decides it case by case.
        $adjustment->recorded_by = null;
        // From the days being charged for, not from the ambient current
        // bakery: run from the console there is none, and a row with a
        // null shop is a row that shows up on every shop's pay sheet.
        $adjustment->bakery_id = $shopId;
        $adjustment->save();
    }

    /** Every person with a late record in the month containing this date. */
    public static function syncMonth(Carbon $anyDayInMonth): int
    {
        [$from, $until] = Jalali::monthRangeFor($anyDayInMonth->copy());

        $users = WorkStart::acrossBakeries()
            ->where('is_late', true)
            ->whereBetween('date', [$from->toDateString(), $until->toDateString()])
            ->pluck('user_id')
            ->filter()
            ->unique();

        foreach ($users as $userId) {
            self::sync((int) $userId, $anyDayInMonth);
        }

        return $users->count();
    }
}
