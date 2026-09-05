<?php

namespace App\Support;

use App\Models\Bakery;
use App\Models\Expense;
use App\Models\FlourPrice;
use App\Models\FlourSale;
use App\Models\Income;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\SalaryPayment;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The single place that decides what counts as income and what counts as a
 * cost. Every report, widget and profit split reads from here, so a new
 * revenue stream cannot appear in one figure and be missing from another.
 *
 * All amounts are in stored Toman; converting for display is the caller's job.
 */
class Ledger
{
    /** Bread sold over the counter. */
    public static function breadSales(Carbon $from, Carbon $to): float
    {
        return round((float) Sale::whereBetween('created_at', [$from, $to])->sum('amount'), 2);
    }

    /** Flour sold out of the warehouse, by the kilo or by the sack. */
    public static function flourSales(Carbon $from, Carbon $to): float
    {
        return round((float) FlourSale::whereBetween('sold_on', [
            $from->toDateString(), $to->toDateString(),
        ])->sum('amount'), 2);
    }

    /** Everything else: subsidies, rent, scrap. */
    public static function otherIncome(Carbon $from, Carbon $to): float
    {
        return round((float) Income::whereBetween('received_on', [
            $from->toDateString(), $to->toDateString(),
        ])->sum('amount'), 2);
    }

    public static function totalIncome(Carbon $from, Carbon $to): float
    {
        return round(
            self::breadSales($from, $to)
            + self::flourSales($from, $to)
            + self::otherIncome($from, $to),
            2
        );
    }

    public static function recordedExpenses(Carbon $from, Carbon $to): float
    {
        return round((float) Expense::whereBetween('spent_on', [
            $from->toDateString(), $to->toDateString(),
        ])->sum('amount'), 2);
    }

    /**
     * What arrived on a lorry: every purchase invoice dated in the window,
     * lines and all.
     *
     * Counted apart from [recordedExpenses] for the same reason salaries
     * are — an invoice is not an expense row, and adding the two names
     * together is how one delivery gets charged twice. The three retired
     * expense categories are the rows this replaced; they are still
     * counted, by [recordedExpenses], because they really happened.
     */
    public static function purchases(Carbon $from, Carbon $to): float
    {
        return round((float) Purchase::whereBetween('purchased_on', [
            $from->toDateString(), $to->toDateString(),
        ])->sum('amount'), 2);
    }

    /**
     * Salaries are counted separately from expenses so payroll is not
     * double-counted against a hand-entered "salary" expense row.
     */
    public static function paidSalaries(Carbon $from, Carbon $to): float
    {
        return round((float) SalaryPayment::paid()->whereBetween('paid_on', [
            $from->toDateString(), $to->toDateString(),
        ])->sum('net_amount'), 2);
    }

    /**
     * What the flour baked in this window cost to buy.
     *
     * The shop's profit was money in less money out, which counts the flour
     * only on the day a purchase happened to be recorded. Bake through a
     * sack bought last month and the bread looked pure profit; buy a lorry
     * load on the last day of the month and the month looked like a loss.
     * Costing the flour as it is consumed puts the bread and the flour it
     * came from in the same period.
     *
     * Flour sold on rather than baked is excluded: its cost belongs to that
     * sale, which already carries its own purchase price.
     */
    public static function flourConsumedCost(Carbon $from, Carbon $to): float
    {
        // Kneading spends flour as 'production' and dusting the chane as
        // 'spray'; both end up in the bread. A withdrawal for any other
        // reason — a sale, a loan to a partner — is not bread and carries
        // its own cost elsewhere.
        $movements = InventoryMovement::query()
            ->whereHas('item', fn ($q) => $q->where('key', InventoryItem::FLOUR))
            ->where('direction', 'out')
            ->whereIn('reason', ['production', 'spray'])
            ->whereBetween('created_at', [$from, $to])
            ->get(['quantity', 'created_at']);

        if ($movements->isEmpty()) {
            return 0.0;
        }

        // Each bake at the price in force the day it happened, so a price
        // rise today cannot reach back and change what last month's bread
        // cost to make. The prices are read once per distinct day rather
        // than per movement — a busy day is many movements and one price.
        $prices = [];
        $total = 0.0;

        foreach ($movements as $movement) {
            $day = $movement->created_at->toDateString();

            if (! array_key_exists($day, $prices)) {
                $prices[$day] = FlourPrice::onDate($movement->created_at)
                    // Falls back to the shop's single figure for an install
                    // that has not recorded a dated price yet.
                    ?? (float) (Bakery::query()->value('flour_purchase_price_per_kg') ?? 0);
            }

            $total += (float) $movement->quantity * $prices[$day];
        }

        return round($total, 2);
    }

    /**
     * Cost of goods sold: what the bread sold in this window cost to make.
     *
     * Only flour for now — salt and yeast are pennies beside it, and
     * guessing at them would make the figure look more precise than the
     * shop's own records support.
     */
    public static function costOfGoodsSold(Carbon $from, Carbon $to): float
    {
        return self::flourConsumedCost($from, $to);
    }

    /** Income less what the goods cost, before wages and overheads. */
    public static function grossProfit(Carbon $from, Carbon $to): float
    {
        return round(self::totalIncome($from, $to) - self::costOfGoodsSold($from, $to), 2);
    }

    public static function totalExpenses(Carbon $from, Carbon $to): float
    {
        return round(
            self::recordedExpenses($from, $to)
            + self::paidSalaries($from, $to)
            + self::purchases($from, $to),
            2
        );
    }

    public static function profit(Carbon $from, Carbon $to): float
    {
        return round(self::totalIncome($from, $to) - self::totalExpenses($from, $to), 2);
    }

    /**
     * Flour bought, as against flour baked.
     *
     * The same money, recognised at two different moments — and the reason
     * the two of them cannot simply be added up.
     */
    public static function flourPurchases(Carbon $from, Carbon $to): float
    {
        // Rows filed before a delivery became a record of its own. The
        // category is no longer offered and these are no longer written,
        // but a month that contains them has to still balance.
        $asExpenses = (float) Expense::whereBetween('spent_on', [
            $from->toDateString(), $to->toDateString(),
        ])->where('category', 'flour')->sum('amount');

        // Only the flour lines of an invoice. A lorry that brought flour
        // and charged for its own unloading spent money on two different
        // things, and only one of them is charged again as it is baked.
        $asPurchaseLines = (float) PurchaseItem::query()
            ->whereHas('purchase', fn ($q) => $q->whereBetween('purchased_on', [
                $from->toDateString(), $to->toDateString(),
            ]))
            ->whereHas('item', fn ($q) => $q->where('key', InventoryItem::FLOUR))
            ->sum('amount');

        return round($asExpenses + $asPurchaseLines, 2);
    }

    /**
     * Everything the shop spent that is not the flour in the bread.
     *
     * Flour purchases come out because [costOfGoodsSold] already charges
     * for the flour, at the moment it is kneaded rather than the day a
     * sack was paid for. Counting both charges the shop twice for one sack:
     * on this month's figures that is 164,640,000 Rial of a 1.7 billion
     * month, and in a leaner month it turns a profit into a loss.
     */
    public static function operatingExpenses(Carbon $from, Carbon $to): float
    {
        return round(self::totalExpenses($from, $to) - self::flourPurchases($from, $to), 2);
    }

    /** The income side broken out, for reports that show where money came from. */
    public static function incomeBreakdown(Carbon $from, Carbon $to): array
    {
        $bread = self::breadSales($from, $to);
        $flour = self::flourSales($from, $to);
        $other = self::otherIncome($from, $to);

        return [
            'bread' => $bread,
            'bread_formatted' => Money::format($bread),
            'flour' => $flour,
            'flour_formatted' => Money::format($flour),
            'other' => $other,
            'other_formatted' => Money::format($other),
            'total' => round($bread + $flour + $other, 2),
            'total_formatted' => Money::format($bread + $flour + $other),
        ];
    }

    /**
     * One figure's daily totals across a whole range, in one query.
     *
     * The series pages ask the same question of the same table once per
     * bucket: a month of days was ninety-four sums over `sales` alone, and
     * six hundred queries for one page. The window is the only thing that
     * changes between those, so the sums are done per day by the database
     * and added up per bucket in PHP.
     *
     * Per day, not per bucket, because the buckets are Jalali weeks and
     * months. Grouping those in SQL means teaching the database a calendar
     * it does not have; grouping by the date it does have is exact, and
     * every bucket is a run of whole days.
     *
     * Keyed 'Y-m-d'. A day with nothing in it is absent rather than zero,
     * so callers must default.
     *
     * @param  Builder  $query
     * @return array<string, float>
     */
    public static function dailySums($query, string $dateColumn, string $sumColumn, Carbon $from, Carbon $to): array
    {
        $rows = $query
            ->whereBetween($dateColumn, [$from, $to])
            ->selectRaw("date({$dateColumn}) as d, sum({$sumColumn}) as total")
            ->groupBy('d')
            ->pluck('total', 'd');

        return $rows->map(fn ($total) => (float) $total)->all();
    }

    /**
     * The same, counting rows rather than summing a column.
     *
     * Its own method rather than a `count(*)` passed to [dailySums]: that
     * builds `sum(count(*))`, which the database rejects, and a helper
     * that takes an expression invites exactly that.
     *
     * @param  Builder  $query
     * @return array<string, float>
     */
    public static function dailyCounts($query, string $dateColumn, Carbon $from, Carbon $to): array
    {
        $rows = $query
            ->whereBetween($dateColumn, [$from, $to])
            ->selectRaw("date({$dateColumn}) as d, count(*) as total")
            ->groupBy('d')
            ->pluck('total', 'd');

        return $rows->map(fn ($total) => (float) $total)->all();
    }

    /**
     * A stock item's daily outflow for the given reasons, in one query.
     *
     * Same shape as [dailySums] and separate from it because the filtering
     * is the interesting part: an item, a direction and a set of reasons.
     * Asked per bucket, the consumption series ran five of these for every
     * day on the chart.
     *
     * A missing item is an empty map rather than an error — a shop that
     * has never stocked yeast still has a chart.
     *
     * @return array<string, float>
     */
    public static function dailyStockOut(
        ?InventoryItem $item,
        array $reasons,
        Carbon $from,
        Carbon $to,
    ): array {
        if ($item === null) {
            return [];
        }

        return self::dailySums(
            $item->movements()
                ->where('direction', 'out')
                ->whereIn('reason', $reasons),
            'created_at',
            'quantity',
            $from,
            $to,
        );
    }

    /**
     * What those daily totals come to between two dates, inclusive.
     *
     * @param  array<string, float>  $daily
     */
    public static function sumDays(array $daily, Carbon $from, Carbon $to): float
    {
        $total = 0.0;

        for ($day = $from->copy()->startOfDay(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $total += $daily[$day->toDateString()] ?? 0.0;
        }

        return round($total, 2);
    }

    /** A single day's income and cost, for the trend chart. */
    public static function dailyTotals(Carbon $day): array
    {
        $date = $day->toDateString();

        $income = (float) Sale::whereDate('created_at', $date)->sum('amount')
            + (float) FlourSale::whereDate('sold_on', $date)->sum('amount')
            + (float) Income::whereDate('received_on', $date)->sum('amount');

        $expense = (float) Expense::whereDate('spent_on', $date)->sum('amount')
            + (float) SalaryPayment::paid()->whereDate('paid_on', $date)->sum('net_amount')
            + (float) Purchase::whereDate('purchased_on', $date)->sum('amount');

        return [round($income, 2), round($expense, 2)];
    }
}
