<?php

namespace App\Support;

use App\Models\ChaneEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What each chane-gir got out of a sack, against what the formula says.
 *
 * The audit has had «عملکرد واقعی کارکنان» at 40% since the first day,
 * with «هدف در برابر واقعی» named as the gap. The shop already records
 * everything this needs: who shaped which batch, how many sacks went into
 * it, and how many chane came out. Nothing new is asked of anybody.
 *
 * **This measures people, so what it refuses to say matters more than
 * what it says.** Three rules, each of which costs coverage on purpose:
 *
 *   - **Only batches somebody shaped alone.** A dough can carry several
 *     chane entries by several people, and nothing records how many of
 *     its sacks each of them worked. Splitting the sacks by output would
 *     make every person's yield identical by construction — the
 *     arithmetic would be circular and the number meaningless. So a
 *     shared batch counts for nobody.
 *   - **A floor under the sample.** One batch is a morning, not a
 *     record. Below [MIN_BAGS] sacks the person is not reported at all,
 *     rather than reported with a caveat nobody reads.
 *   - **The sample size travels with the figure.** «۴۲ چانه از هر کیسه»
 *     means one thing over six sacks and another over sixty, and the
 *     person reading it is the one who knows which.
 *
 * Nanino is converted by weight rather than dropped: a batch shaped small
 * yields fewer chane for the same flour, and counting that as poor work
 * would punish whoever was put on the nanino bench.
 */
class StaffYield
{
    /** Below this many sacks, a person is not reported. */
    public const MIN_BAGS = 20.0;

    /**
     * How far under the formula counts as worth the owner's attention.
     *
     * Not a threshold for a conversation about somebody's job — a bench
     * runs a little under the formula on an ordinary day, and the formula
     * itself is a target rather than a law. Ten per cent is where the
     * flour lost stops being rounding.
     */
    public const ATTENTION_RATIO = 0.9;

    /**
     * @return Collection<int, array{
     *     user: string, bags: float, chane: int, perBag: float,
     *     expectedPerBag: int, ratio: float, isLow: bool, batches: int
     * }>
     */
    public static function between(Carbon $from, Carbon $to): Collection
    {
        $formula = DoughFormula::fromBakery();
        $expected = $formula->normalChaneCount(1);

        // Without a chane weight the formula has no target, and a yield
        // with nothing to compare it against is a number pretending to be
        // a judgement.
        if (! $expected || ! $formula->normalChaneWeightKg) {
            return collect();
        }

        $rows = ChaneEntry::query()
            ->with(['user:id,name', 'doughEntry:id,bag_count'])
            // The sole-shaper rule, as a condition on the batch rather
            // than a filter afterwards: a dough with a second chane entry
            // is excluded whoever wrote it.
            ->whereHas('doughEntry', fn ($q) => $q->has('chaneEntries', '=', 1))
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('user_id')
            ->get();

        return $rows
            ->filter(fn (ChaneEntry $e) => ($e->doughEntry?->bag_count ?? 0) > 0)
            ->groupBy('user_id')
            ->map(function (Collection $entries) use ($formula, $expected) {
                $bags = (float) $entries->sum(fn (ChaneEntry $e) => (float) $e->doughEntry->bag_count);

                // Nanino carried across at its own weight, so a bench put
                // on the small loaf is not read as a bench working badly.
                $chane = (int) round($entries->sum(
                    fn (ChaneEntry $e) => (int) $e->chane_count
                        + (float) $e->nanino_weight_kg / $formula->normalChaneWeightKg
                ));

                $perBag = round($chane / $bags, 1);

                return [
                    'user' => $entries->first()->user?->name ?? '—',
                    'bags' => round($bags, 1),
                    'chane' => $chane,
                    'batches' => $entries->count(),
                    'perBag' => $perBag,
                    'expectedPerBag' => $expected,
                    'ratio' => round($perBag / $expected, 3),
                    'isLow' => $perBag < $expected * self::ATTENTION_RATIO,
                ];
            })
            ->filter(fn (array $row) => $row['bags'] >= self::MIN_BAGS)
            ->sortByDesc('perBag')
            ->values();
    }
}
