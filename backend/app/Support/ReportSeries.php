<?php

namespace App\Support;

use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\FlourSale;
use App\Models\Income;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\SalaryPayment;
use App\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The shop's figures cut into buckets — a day, a week or a month at a time.
 *
 * Both the API and the panel's reports page read from here, so a change to
 * what counts as income or as consumption lands in both at once rather than
 * being fixed in one and quietly left wrong in the other.
 */
class ReportSeries
{
    /** Money in and money out, bucket by bucket. */
    public static function financial(Carbon $from, Carbon $to, string $granularity): Collection
    {
        // Five questions asked once each over the whole range, rather than
        // once per bucket. The window was the only thing that changed
        // between them, and a month of days paid a hundred and fifty
        // queries for that.
        $bread = Ledger::dailySums(Sale::query(), 'created_at', 'amount', $from, $to);
        $flourSold = Ledger::dailySums(FlourSale::query(), 'sold_on', 'amount', $from, $to);
        $other = Ledger::dailySums(Income::query(), 'received_on', 'amount', $from, $to);
        $spent = Ledger::dailySums(Expense::query(), 'spent_on', 'amount', $from, $to);
        $wages = Ledger::dailySums(SalaryPayment::paid(), 'paid_on', 'net_amount', $from, $to);

        return collect(PeriodBuckets::build($from, $to, $granularity))
            ->map(function (array $bucket) use ($bread, $flourSold, $other, $spent, $wages) {
                $from = $bucket['from'];
                $to = $bucket['to'];

                // Rounded per figure and then combined, in the same order
                // `incomeBreakdown` did it. Adding first and rounding once
                // would be a different number in the last rial, and the
                // point of this change is that it is not.
                $income = [
                    'bread' => Ledger::sumDays($bread, $from, $to),
                    'flour' => Ledger::sumDays($flourSold, $from, $to),
                    'other' => Ledger::sumDays($other, $from, $to),
                ];
                $income['total'] = round(
                    $income['bread'] + $income['flour'] + $income['other'],
                    2,
                );

                $recorded = Ledger::sumDays($spent, $from, $to);
                $salaries = Ledger::sumDays($wages, $from, $to);
                $expense = round($recorded + $salaries, 2);
                $profit = round($income['total'] - $expense, 2);

                return [
                    'key' => $bucket['key'],
                    'label' => $bucket['label'],
                    'from' => $bucket['from']->toDateString(),
                    'to' => $bucket['to']->toDateString(),
                    'income' => $income['total'],
                    'income_formatted' => Money::format($income['total']),
                    'income_bread' => $income['bread'],
                    'income_flour' => $income['flour'],
                    'income_other' => $income['other'],
                    'expense' => $expense,
                    'expense_formatted' => Money::format($expense),
                    'expense_recorded' => round($recorded, 2),
                    'expense_salaries' => round($salaries, 2),
                    'profit' => $profit,
                    'profit_formatted' => Money::format($profit),
                ];
            })
            ->values();
    }

    /**
     * What the shop got through, bucket by bucket.
     *
     * Flour is the two ways a bakery actually eats it — the kneaded batch
     * and the flour thrown on the bench — kept apart from flour that was
     * sold on, which left the store without ever being baked.
     */
    public static function consumption(Carbon $from, Carbon $to, string $granularity): Collection
    {
        $items = InventoryItem::all()->keyBy('key');
        $flour = $items->get(InventoryItem::FLOUR);

        // Six questions asked once each over the whole range rather than
        // once per bucket. A month of days ran five sums over the movement
        // ledger for every day on the chart.
        $kneaded = Ledger::dailyStockOut($flour, ['production'], $from, $to);
        $bench = Ledger::dailyStockOut($flour, ['spray'], $from, $to);
        $soldOn = Ledger::dailyStockOut($flour, ['flour_sale', 'consignment_out'], $from, $to);
        $salt = Ledger::dailyStockOut($items->get(InventoryItem::SALT), ['production'], $from, $to);
        $yeast = Ledger::dailyStockOut($items->get('yeast_dry'), ['production'], $from, $to);
        $sacks = Ledger::dailySums(DoughEntry::query(), 'created_at', 'bag_count', $from, $to);

        return collect(PeriodBuckets::build($from, $to, $granularity))
            ->map(function (array $bucket) use (
                $kneaded, $bench, $soldOn, $salt, $yeast, $sacks,
            ) {
                $from = $bucket['from'];
                $to = $bucket['to'];

                // Three decimals, as before: flour is weighed to the gram
                // and rounding it to two would lose a kilo over a month.
                $used = fn (array $daily) => round(Ledger::sumDays($daily, $from, $to), 3);

                $production = $used($kneaded);
                $spray = $used($bench);

                return [
                    'key' => $bucket['key'],
                    'label' => $bucket['label'],
                    'from' => $bucket['from']->toDateString(),
                    'to' => $bucket['to']->toDateString(),
                    'bags_kneaded' => Ledger::sumDays($sacks, $from, $to),
                    'flour_production_kg' => $production,
                    'flour_spray_kg' => $spray,
                    'flour_used_kg' => round($production + $spray, 3),
                    // Sold on rather than baked — reported beside the usage
                    // so the store's outflow still adds up, without being
                    // counted as consumption.
                    'flour_sold_kg' => $used($soldOn),
                    'salt_kg' => $used($salt),
                    // yeast_wet_kg was here until 1405/06/08. The tub was
                    // removed, and a series that is zero for ever is a line
                    // on a chart saying nothing.
                    'yeast_dry_kg' => $used($yeast),
                ];
            })
            ->values();
    }

    /**
     * What was kneaded, shaped and sold, bucket by bucket.
     *
     * Nanino is stored as a weight, so its loaf count is derived the way
     * every other screen derives it rather than being counted twice.
     */
    public static function production(Carbon $from, Carbon $to, string $granularity): Collection
    {
        $formula = DoughFormula::fromBakery();

        // Seven questions asked once each over the whole range. Per bucket
        // this was the heaviest part of the page: a month of days ran
        // sixty-two sums over `dough_entries` alone.
        $batches = Ledger::dailyCounts(DoughEntry::query(), 'created_at', $from, $to);
        $sacks = Ledger::dailySums(DoughEntry::query(), 'created_at', 'bag_count', $from, $to);
        $chaneCount = Ledger::dailySums(ChaneEntry::query(), 'created_at', 'chane_count', $from, $to);
        $chaneWeight = Ledger::dailySums(ChaneEntry::query(), 'created_at', 'normal_weight_kg', $from, $to);
        $naninoWeightDaily = Ledger::dailySums(ChaneEntry::query(), 'created_at', 'nanino_weight_kg', $from, $to);
        $loaves = Ledger::dailySums(Sale::query(), 'created_at', 'bread_count', $from, $to);
        $takings = Ledger::dailySums(Sale::query(), 'created_at', 'amount', $from, $to);

        return collect(PeriodBuckets::build($from, $to, $granularity))
            ->map(function (array $bucket) use (
                $formula, $batches, $sacks, $chaneCount, $chaneWeight,
                $naninoWeightDaily, $loaves, $takings,
            ) {
                $from = $bucket['from'];
                $to = $bucket['to'];

                $naninoWeight = Ledger::sumDays($naninoWeightDaily, $from, $to);
                $amount = Ledger::sumDays($takings, $from, $to);

                return [
                    'key' => $bucket['key'],
                    'label' => $bucket['label'],
                    'from' => $bucket['from']->toDateString(),
                    'to' => $bucket['to']->toDateString(),
                    'dough_entries' => (int) Ledger::sumDays($batches, $from, $to),
                    'bags_kneaded' => Ledger::sumDays($sacks, $from, $to),
                    'normal_chane_count' => (int) Ledger::sumDays($chaneCount, $from, $to),
                    'nanino_chane_count' => $formula->naninoCountForWeight($naninoWeight),
                    'normal_weight_kg' => Ledger::sumDays($chaneWeight, $from, $to),
                    'nanino_weight_kg' => round($naninoWeight, 2),
                    'bread_sold' => (int) Ledger::sumDays($loaves, $from, $to),
                    'sales_amount' => $amount,
                    'sales_amount_formatted' => Money::format($amount),
                ];
            })
            ->values();
    }

    /**
     * Where the flour went over one range, and where it came from.
     *
     * The owner asked the question in four words — «آرد کجا رفت» — and
     * until now nothing answered it. The consumption series says how much
     * was baked each day; it does not say that of the month's sacks, four
     * in five were baked, one in seven went to a partner bakery and never
     * came back, and a handful was dusted on the bench.
     *
     * Read from the warehouse ledger, which is the only place all of it
     * meets: production, spray, lending, flour sales and every correction
     * are separate screens and one set of movements.
     *
     * **Reversals are netted against what they undo, not shown as flour
     * arriving.** A batch deleted the next day is not a delivery; it is a
     * bake that did not happen, and a report that lists it under «آمد»
     * tells the owner sacks turned up that nobody bought. Where the
     * reversal says which movement it cancels it is netted against that
     * one's own destination; the column arrived on 1405/05/25, so anything
     * cancelled before it falls back to its family — a production reversal
     * to the bake, a sale reversal to the sale.
     *
     * Sacks lead and kilograms follow, because sacks are what the shop
     * counts and what its quota is written in.
     *
     * @return array{
     *     opening_kg: float, closing_kg: float,
     *     in: array<int, array{reason: string, label: string, kg: float, bags: ?float, share: float}>,
     *     out: array<int, array{reason: string, label: string, kg: float, bags: ?float, share: float}>,
     *     in_kg: float, out_kg: float, in_bags: ?float, out_bags: ?float,
     *     opening_bags: ?float, closing_bags: ?float, balances: bool
     * }
     */
    public static function flourJourney(Carbon $from, Carbon $to): array
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $bagWeight = DoughFormula::fromBakery()->bagWeightKg;

        $opening = (float) $flour->movements()->where('created_at', '<', $from)
            ->selectRaw("coalesce(sum(case when direction = 'in' then quantity else -quantity end), 0) as net")
            ->value('net');

        $movements = $flour->movements()
            ->whereBetween('created_at', [$from, $to])
            ->with('reverses')
            ->get();

        // Net out per destination: what left, less anything that came back
        // to the same place.
        $net = [];

        foreach ($movements as $movement) {
            $destination = self::flourDestination($movement);
            $net[$destination] ??= 0.0;
            $net[$destination] += ($movement->direction === 'out' ? 1 : -1) * (float) $movement->quantity;
        }

        $out = [];
        $in = [];

        foreach ($net as $reason => $kg) {
            $kg = round($kg, 3);

            if (abs($kg) < 0.001) {
                continue;
            }

            // A destination whose net is negative gave more back than it
            // took, which for a stocktake or a correction is the ordinary
            // case and for a bake means a batch was cancelled.
            $row = [
                'reason' => $reason,
                'label' => InventoryMovement::REASONS[$reason] ?? $reason,
                'kg' => abs($kg),
                'bags' => $bagWeight > 0 ? round(abs($kg) / $bagWeight, 2) : null,
                'share' => 0.0,
            ];

            $kg > 0 ? $out[] = $row : $in[] = $row;
        }

        $outKg = round(array_sum(array_column($out, 'kg')), 3);
        $inKg = round(array_sum(array_column($in, 'kg')), 3);

        $share = function (array $rows, float $total) {
            foreach ($rows as $i => $row) {
                $rows[$i]['share'] = $total > 0 ? round($row['kg'] / $total * 100, 1) : 0.0;
            }

            // Biggest first: the question is where the flour went, and the
            // answer is the top line.
            usort($rows, fn ($a, $b) => $b['kg'] <=> $a['kg']);

            return $rows;
        };

        $closing = round($opening + $inKg - $outKg, 3);

        return [
            'opening_kg' => round($opening, 3),
            'closing_kg' => $closing,
            'opening_bags' => $bagWeight > 0 ? round($opening / $bagWeight, 2) : null,
            'closing_bags' => $bagWeight > 0 ? round($closing / $bagWeight, 2) : null,
            'in' => $share($in, $inKg),
            'out' => $share($out, $outKg),
            'in_kg' => $inKg,
            'out_kg' => $outKg,
            'in_bags' => $bagWeight > 0 ? round($inKg / $bagWeight, 2) : null,
            'out_bags' => $bagWeight > 0 ? round($outKg / $bagWeight, 2) : null,
            // Derived from one ledger, so this cannot fail by arithmetic —
            // it is here because the day it does fail is the day something
            // wrote a movement this report does not know how to place, and
            // a report that quietly drops flour is worse than none.
            'balances' => abs(round($opening + $inKg - $outKg, 3) - $closing) < 0.001,
        ];
    }

    /**
     * Which destination a movement belongs to.
     *
     * A reversal belongs to whatever it cancels, so that cancelling a bake
     * reduces the bake rather than appearing as a delivery of flour.
     */
    private static function flourDestination(InventoryMovement $movement): string
    {
        if ($movement->reverses) {
            return $movement->reverses->reason;
        }

        return match ($movement->reason) {
            // Before `reverses_movement_id` existed there is no link to
            // follow, so the family is the best that can be said. Spray
            // reversals land on the bake, which is where the flour was
            // going anyway.
            'production_reversal' => 'production',
            'flour_sale_reversal' => 'flour_sale',
            'consignment_return' => 'consignment_out',
            default => $movement->reason,
        };
    }
}
