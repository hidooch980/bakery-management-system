<?php

namespace App\Support;

use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
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
        return collect(PeriodBuckets::build($from, $to, $granularity))
            ->map(function (array $bucket) {
                $income = Ledger::incomeBreakdown($bucket['from'], $bucket['to']);
                $recorded = Ledger::recordedExpenses($bucket['from'], $bucket['to']);
                $salaries = Ledger::paidSalaries($bucket['from'], $bucket['to']);
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

        return collect(PeriodBuckets::build($from, $to, $granularity))
            ->map(function (array $bucket) use ($items) {
                $window = [$bucket['from'], $bucket['to']];

                $used = function (?InventoryItem $item, array $reasons) use ($window) {
                    if (! $item) {
                        return 0.0;
                    }

                    return round((float) $item->movements()
                        ->where('direction', 'out')
                        ->whereIn('reason', $reasons)
                        ->whereBetween('created_at', $window)
                        ->sum('quantity'), 3);
                };

                $flour = $items->get(InventoryItem::FLOUR);
                $production = $used($flour, ['production']);
                $spray = $used($flour, ['spray']);

                return [
                    'key' => $bucket['key'],
                    'label' => $bucket['label'],
                    'from' => $bucket['from']->toDateString(),
                    'to' => $bucket['to']->toDateString(),
                    'bags_kneaded' => (float) DoughEntry::whereBetween('created_at', $window)->sum('bag_count'),
                    'flour_production_kg' => $production,
                    'flour_spray_kg' => $spray,
                    'flour_used_kg' => round($production + $spray, 3),
                    // Sold on rather than baked — reported beside the usage
                    // so the store's outflow still adds up, without being
                    // counted as consumption.
                    'flour_sold_kg' => $used($flour, ['flour_sale', 'consignment_out']),
                    'salt_kg' => $used($items->get(InventoryItem::SALT), ['production']),
                    // yeast_wet_kg was here until 1405/06/08. The tub was
                    // removed, and a series that is zero for ever is a line
                    // on a chart saying nothing.
                    'yeast_dry_kg' => $used($items->get('yeast_dry'), ['production']),
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

        return collect(PeriodBuckets::build($from, $to, $granularity))
            ->map(function (array $bucket) use ($formula) {
                $window = [$bucket['from'], $bucket['to']];

                $chane = ChaneEntry::whereBetween('created_at', $window);
                $normalCount = (int) $chane->clone()->sum('chane_count');
                $naninoWeight = (float) $chane->clone()->sum('nanino_weight_kg');
                $naninoCount = $formula->naninoCountForWeight($naninoWeight);
                $sales = Sale::whereBetween('created_at', $window);

                return [
                    'key' => $bucket['key'],
                    'label' => $bucket['label'],
                    'from' => $bucket['from']->toDateString(),
                    'to' => $bucket['to']->toDateString(),
                    'dough_entries' => DoughEntry::whereBetween('created_at', $window)->count(),
                    'bags_kneaded' => (float) DoughEntry::whereBetween('created_at', $window)->sum('bag_count'),
                    'normal_chane_count' => $normalCount,
                    'nanino_chane_count' => $naninoCount,
                    'normal_weight_kg' => round((float) $chane->clone()->sum('normal_weight_kg'), 2),
                    'nanino_weight_kg' => round($naninoWeight, 2),
                    'bread_sold' => (int) $sales->clone()->sum('bread_count'),
                    'sales_amount' => round((float) $sales->clone()->sum('amount'), 2),
                    'sales_amount_formatted' => Money::format($sales->clone()->sum('amount')),
                ];
            })
            ->values();
    }
}
