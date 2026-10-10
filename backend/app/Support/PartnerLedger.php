<?php

namespace App\Support;

use App\Models\ConsignmentFlour;
use App\Models\Customer;
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
     * Every partner the shop has an open balance with, largest debt to the
     * shop at the top.
     *
     * «باز» بعد از خالص شدنِ خودکار (PartnerNetting): ثبتِ طرف مقابل از
     * قدیمی‌ترین ماندهٔ باز کم می‌شود و دیگر دکمهٔ تسویه‌ای در کار نیست.
     *
     * @return Collection<int, PartnerPosition>
     */
    public static function positions(): Collection
    {
        return self::all()
            ->filter(fn (PartnerPosition $p) => abs($p->netBags()) > 0.001)
            ->values();
    }

    /**
     * «همه اسما باشه»: همهٔ همکاران تعریف‌شده — حتی با ماندهٔ صفر یا بی‌هیچ
     * ثبتی — به‌اضافهٔ نام‌های آزادِ ردیف‌های قدیمی که هنوز مانده دارند.
     * به ترتیب مانده (طلب ما بالا، بدهی ما پایین) و بعد نام.
     *
     * @return Collection<int, PartnerPosition>
     */
    public static function all(): Collection
    {
        $records = ConsignmentFlour::query()->with(['partner', 'returns'])->get();
        $open = PartnerNetting::open($records);

        $positions = $records
            ->groupBy(fn (ConsignmentFlour $c) => PartnerNetting::keyOf($c))
            ->map(fn (Collection $group, $key) => self::position((string) $key, $group, $open));

        // نام آزادِ قدیمی که حسابش صاف است فقط تاریخچه است.
        $positions = $positions->filter(fn (PartnerPosition $p) => $p->customerId !== null || abs($p->netBags()) > 0.001);

        foreach (Customer::query()->partners()->get() as $partner) {
            if (! $positions->has((string) $partner->id)) {
                $positions->put((string) $partner->id, new PartnerPosition(
                    key: (string) $partner->id,
                    customerId: $partner->id,
                    name: $partner->name,
                    phone: $partner->phone,
                    bagsLent: 0.0,
                    bagsBorrowed: 0.0,
                    lendingCount: 0,
                    borrowingCount: 0,
                    oldestLentOn: null,
                    dateIsApproximate: false,
                    records: collect(),
                    entries: 0,
                ));
            }
        }

        return $positions
            ->sort(fn (PartnerPosition $a, PartnerPosition $b) => [$b->netBags(), $a->name] <=> [$a->netBags(), $b->name])
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
     * @param  Collection<int, ConsignmentFlour>  $records  همهٔ ردیف‌های یک همکار
     * @param  array<int, float>  $open  کیسه‌های باز پس از خالص شدن
     */
    private static function position(string $key, Collection $records, array $open): PartnerPosition
    {
        $openOf = fn (ConsignmentFlour $c) => $open[$c->id] ?? 0.0;
        $stillOpen = $records->filter(fn (ConsignmentFlour $c) => $openOf($c) > 0.001);

        $lent = $stillOpen->where('direction', 'lent');
        $borrowed = $stillOpen->where('direction', 'borrowed');

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
            bagsLent: round($lent->sum($openOf), 2),
            bagsBorrowed: round($borrowed->sum($openOf), 2),
            lendingCount: $lent->count(),
            borrowingCount: $borrowed->count(),
            oldestLentOn: $oldestLent ? Carbon::parse($oldestLent) : null,
            // A guess anywhere among the open lendings makes the whole
            // partner's age a guess: the oldest sack may be one whose day
            // nobody knows.
            dateIsApproximate: $lent->contains(fn (ConsignmentFlour $c) => (bool) $c->date_is_approximate),
            records: $stillOpen->sortBy('occurred_on')->values(),
            entries: $records->count(),
            openBags: array_intersect_key($open, $records->keyBy('id')->all()),
            grossLent: round($records->where('direction', 'lent')->sum(fn (ConsignmentFlour $c) => $c->outstandingBags()), 2),
            grossBorrowed: round($records->where('direction', 'borrowed')->sum(fn (ConsignmentFlour $c) => $c->outstandingBags()), 2),
        );
    }
}
