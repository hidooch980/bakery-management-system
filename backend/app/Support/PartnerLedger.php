<?php

namespace App\Support;

use App\Models\ConsignmentFlour;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where the shop stands with each partner bakery, in sacks.
 *
 * Flour goes both ways with the bakeries around here, and the two
 * directions are the same conversation: نانوایی کنت had twenty sacks of
 * the shop's and the shop had twelve of theirs, and the only number
 * either of them would recognise is eight. Chasing twenty is asking for
 * flour that is not owed.
 *
 * One class rather than a query in the scanner and another in the report,
 * because two places that count the same sacks are two places that will
 * eventually disagree — and the owner would have no way of telling which
 * screen was lying. Which had already happened: the handset's partner
 * report has netted the two directions since it was written, while the
 * warning in the issue centre counted only what went out and told the
 * owner to chase twenty sacks the app was calling eight.
 *
 * `ConsignmentFlourController::partners()` still does its own counting.
 * It is left alone deliberately — the handsets read that JSON and its
 * age is measured over both directions rather than only the lendings, so
 * moving it here would change what an installed app displays for a
 * tidiness nobody asked for. If it is ever brought over, that difference
 * is the thing to decide first.
 */
class PartnerLedger
{
    /**
     * A fortnight is the line for chasing. Sacks go back and forth within
     * a week here as a matter of course; past two weeks it has stopped
     * being the ordinary rhythm and become flour nobody is asking for.
     */
    public const CHASE_AFTER_DAYS = 14;

    /**
     * Every partner the shop has an open consignment with, net position
     * first, largest debt to the shop at the top.
     *
     * @return Collection<int, PartnerPosition>
     */
    public static function positions(): Collection
    {
        $open = ConsignmentFlour::query()
            ->whereNull('settled_on')
            ->with('partner')
            ->get();

        return $open
            ->groupBy(fn (ConsignmentFlour $c) => $c->customer_id ?? $c->partner_name)
            ->map(fn (Collection $records, $key) => self::position((string) $key, $records))
            ->sortByDesc(fn (PartnerPosition $p) => $p->netBags())
            ->values();
    }

    /** The position with one named partner, or null if nothing is open. */
    public static function for(int $customerId): ?PartnerPosition
    {
        return self::positions()->first(
            fn (PartnerPosition $p) => $p->customerId === $customerId
        );
    }

    /**
     * @param  Collection<int, ConsignmentFlour>  $records
     */
    private static function position(string $key, Collection $records): PartnerPosition
    {
        $lent = $records->where('direction', 'lent');
        $borrowed = $records->where('direction', 'borrowed');

        $bagsLent = round($lent->sum(fn (ConsignmentFlour $c) => (float) $c->bags), 2);
        $bagsBorrowed = round($borrowed->sum(fn (ConsignmentFlour $c) => (float) $c->bags), 2);

        // Only the sacks that left the shop have an age worth chasing.
        // What the shop borrowed sits in its own store, where the balance
        // already shows it.
        $oldestLent = $lent->min(fn (ConsignmentFlour $c) => $c->occurred_on);

        $first = $records->first();

        return new PartnerPosition(
            key: $key,
            customerId: $first->customer_id,
            name: $first->partner_label ?: 'همکار بی‌نام',
            phone: $first->partner?->phone ?: $first->partner_phone,
            bagsLent: $bagsLent,
            bagsBorrowed: $bagsBorrowed,
            lendingCount: $lent->count(),
            borrowingCount: $borrowed->count(),
            oldestLentOn: $oldestLent ? Carbon::parse($oldestLent) : null,
            // A guess anywhere among the open lendings makes the whole
            // partner's age a guess: the oldest sack may be one whose day
            // nobody knows.
            dateIsApproximate: $lent->contains(fn (ConsignmentFlour $c) => (bool) $c->date_is_approximate),
            records: $records->sortBy('occurred_on')->values(),
        );
    }
}
