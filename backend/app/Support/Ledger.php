<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\FlourSale;
use App\Models\Income;
use App\Models\Sale;
use App\Models\SalaryPayment;
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
     * Salaries are counted separately from expenses so payroll is not
     * double-counted against a hand-entered "salary" expense row.
     */
    public static function paidSalaries(Carbon $from, Carbon $to): float
    {
        return round((float) SalaryPayment::paid()->whereBetween('paid_on', [
            $from->toDateString(), $to->toDateString(),
        ])->sum('net_amount'), 2);
    }

    public static function totalExpenses(Carbon $from, Carbon $to): float
    {
        return round(self::recordedExpenses($from, $to) + self::paidSalaries($from, $to), 2);
    }

    public static function profit(Carbon $from, Carbon $to): float
    {
        return round(self::totalIncome($from, $to) - self::totalExpenses($from, $to), 2);
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

    /** A single day's income and cost, for the trend chart. */
    public static function dailyTotals(Carbon $day): array
    {
        $date = $day->toDateString();

        $income = (float) Sale::whereDate('created_at', $date)->sum('amount')
            + (float) FlourSale::whereDate('sold_on', $date)->sum('amount')
            + (float) Income::whereDate('received_on', $date)->sum('amount');

        $expense = (float) Expense::whereDate('spent_on', $date)->sum('amount')
            + (float) SalaryPayment::paid()->whereDate('paid_on', $date)->sum('net_amount');

        return [round($income, 2), round($expense, 2)];
    }
}
