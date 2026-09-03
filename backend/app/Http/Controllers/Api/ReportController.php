<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\BakeryShare;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\FlourSale;
use App\Models\Holiday;
use App\Models\InventoryItem;
use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Models\User;
use App\Support\AppCalendar;
use App\Support\DoughFormula;
use App\Support\Jalali;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\PeriodBuckets;
use App\Support\ReportSeries;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Admin-facing reporting. All endpoints accept optional `from` / `to`
 * date filters and default to the current day.
 */
class ReportController extends Controller
{
    use ApiResponse;

    public function dashboard(): JsonResponse
    {
        $today = now()->toDateString();
        $chaneCount = (int) ChaneEntry::whereDate('created_at', $today)->sum('chane_count');
        $formula = DoughFormula::fromBakery();
        $naninoEquivalent = $formula->naninoEquivalentForNormalCount($chaneCount);

        return $this->success([
            'today' => [
                'dough_bags' => (int) DoughEntry::whereDate('created_at', $today)->sum('bag_count'),
                'chane_count' => $chaneCount,
                'sales_count' => Sale::whereDate('created_at', $today)->count(),
                'sales_amount' => round((float) Sale::whereDate('created_at', $today)->sum('amount'), 2),
                'attendance_count' => Attendance::where('date', $today)->count(),
                // What-if: today's normal chane, expressed as nanino loaves.
                'normal_as_nanino_equivalent' => $naninoEquivalent,
                'normal_as_nanino_announcement' => $naninoEquivalent === null
                    ? null
                    : "چانه‌های عادی امروز ({$chaneCount} عدد) معادل {$naninoEquivalent} نان نانینو است.",
            ],
            'queues' => [
                'pending_dough' => DoughEntry::pending()->count(),
                'pending_chane' => ChaneEntry::pending()->count(),
            ],
            'staff' => [
                'total' => User::ofCurrentBakery()->count(),
                'active' => User::ofCurrentBakery()->where('is_active', true)->count(),
            ],
            'flour_balance_kg' => $this->flourBalance(),
            // So a client that shows raw figures still labels them correctly.
            'currency' => Money::currency(),
            'currency_label' => Money::label(),
            'sales_amount_formatted' => Money::format(
                Sale::whereDate('created_at', $today)->sum('amount')
            ),
        ]);
    }

    public function production(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $dough = DoughEntry::whereBetween('created_at', [$from, $to]);
        $chane = ChaneEntry::whereBetween('created_at', [$from, $to]);

        return $this->success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_dough_bags' => (int) $dough->sum('bag_count'),
            'total_dough_entries' => $dough->count(),
            'total_chane_count' => (int) $chane->sum('chane_count'),
            // Normal chane is the production figure; nanino is shown beside it
            // for comparison but is not part of the total.
            'total_weight_kg' => round((float) $chane->sum('normal_weight_kg'), 2),
            'total_normal_weight_kg' => round((float) $chane->sum('normal_weight_kg'), 2),
            'total_nanino_weight_kg' => round((float) $chane->sum('nanino_weight_kg'), 2),
            // The real nanino count for the period, derived from its weight —
            // previously only ever reported for today, nowhere over a range.
            'total_nanino_count' => DoughFormula::fromBakery()
                ->naninoCountForWeight((float) $chane->sum('nanino_weight_kg')),
            'total_spray_flour_kg' => round((float) $chane->sum('spray_flour_kg'), 2),
            // Day-by-day dough count, so the range total isn't the only
            // figure available — how many batches were made on which day.
            'daily' => $this->dailyDoughCounts($from, $to),
        ]);
    }

    /**
     * What each day in the range produced: the dough kneaded, and the bread
     * baked from it counting both systems. Capped the same way
     * financialTrend is, so an unbounded range cannot blow up the response.
     */
    private function dailyDoughCounts($from, $to): array
    {
        $formula = DoughFormula::fromBakery();

        $days = collect();
        $cursor = $from->copy()->startOfDay();

        while ($cursor->lte($to) && $days->count() < 120) {
            $date = $cursor->toDateString();

            $entries = DoughEntry::whereDate('created_at', $date)->get();
            $chane = ChaneEntry::whereDate('created_at', $date)->get();

            // Nanino is stored as a weight, so the loaf count is derived
            // the same way every other screen derives it.
            $normalCount = (int) $chane->sum('chane_count');
            $naninoCount = $formula->naninoCountForWeight(
                (float) $chane->sum('nanino_weight_kg')
            );

            $days->push([
                'date' => $date,
                'date_display' => Jalali::date($cursor),
                'dough_entries' => $entries->count(),
                'dough_bags' => (int) $entries->sum('bag_count'),
                'normal_chane_count' => $normalCount,
                'nanino_chane_count' => $naninoCount,
                'total_bread_count' => $normalCount + $naninoCount,
            ]);

            $cursor->addDay();
        }

        return $days->all();
    }

    public function sales(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $sales = Sale::whereBetween('created_at', [$from, $to])->get();

        return $this->success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => Money::currency(),
            'currency_label' => Money::label(),
            'count' => $sales->count(),
            'bread_count' => (int) $sales->sum('bread_count'),
            'total_amount' => round((float) $sales->sum('amount'), 2),
            'total_amount_formatted' => Money::format($sales->sum('amount')),
            'by_payment_type' => $sales->groupBy('payment_type')->map(fn ($group) => [
                'count' => $group->count(),
                'bread_count' => (int) $group->sum('bread_count'),
                'amount' => round((float) $group->sum('amount'), 2),
                'amount_formatted' => Money::format($group->sum('amount')),
            ]),
            'by_seller' => $sales->groupBy('user_id')->map(fn ($group) => [
                'seller' => User::find($group->first()->user_id)?->name,
                'count' => $group->count(),
                'amount' => round((float) $group->sum('amount'), 2),
                'amount_formatted' => Money::format($group->sum('amount')),
            ])->values(),
        ]);
    }

    public function flourConsumption(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return $this->success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'flour_in_kg' => round((float) InventoryItem::ofKey(InventoryItem::FLOUR)->movements()
                ->where('direction', 'in')
                ->whereBetween('created_at', [$from, $to])->sum('quantity'), 2),
            'flour_out_kg' => round((float) InventoryItem::ofKey(InventoryItem::FLOUR)->movements()
                ->where('direction', 'out')
                ->whereBetween('created_at', [$from, $to])->sum('quantity'), 2),
            'spray_flour_kg' => round((float) ChaneEntry::whereBetween('created_at', [$from, $to])
                ->sum('spray_flour_kg'), 2),
            'current_balance_kg' => $this->flourBalance(),
        ]);
    }

    /**
     * Production efficiency: chane produced and dough weight per flour bag.
     */
    public function efficiency(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $bags = (int) DoughEntry::whereBetween('created_at', [$from, $to])->sum('bag_count');
        $chane = ChaneEntry::whereBetween('created_at', [$from, $to]);
        $chaneCount = (int) $chane->sum('chane_count');
        // Efficiency measures real output, so nanino is excluded.
        $totalWeight = (float) $chane->sum('normal_weight_kg');

        return $this->success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_bags' => $bags,
            'total_chane' => $chaneCount,
            'total_weight_kg' => round($totalWeight, 2),
            'chane_per_bag' => $bags > 0 ? round($chaneCount / $bags, 2) : 0,
            'weight_per_bag_kg' => $bags > 0 ? round($totalWeight / $bags, 2) : 0,
        ]);
    }

    /**
     * Staff attendance report — this is where the admin sees check-in times.
     */
    public function attendance(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        // recordedBy comes along so the admin reading the sheet can tell a
        // tick someone made for themselves from one the seller entered.
        $records = Attendance::with(['user:id,name', 'recordedBy:id,name'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->orderByDesc('date')
            ->orderByDesc('checked_in_at')
            ->paginate(50);

        return $this->success($records);
    }

    /**
     * Attendance coverage for a period, counting only the days the bakery
     * was actually open — a closed day is not an absence.
     */
    public function attendanceSummary(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $holidays = Holiday::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get();

        // `to` is the end of its day, so compare whole days to avoid a
        // fractional difference counting as an extra day.
        $totalDays = (int) $from->copy()->startOfDay()
            ->diffInDays($to->copy()->startOfDay()) + 1;
        $workingDays = max($totalDays - $holidays->count(), 0);

        $activeStaff = User::ofCurrentBakery()->where('is_active', true)->count();
        $expected = $workingDays * $activeStaff;

        $actual = Attendance::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('date', $holidays->pluck('date')->map->toDateString())
            ->count();

        return $this->success([
            'from_display' => AppCalendar::date($from),
            'to_display' => AppCalendar::date($to),
            'total_days' => $totalDays,
            'holiday_count' => $holidays->count(),
            'working_days' => $workingDays,
            'active_staff' => $activeStaff,
            'expected_check_ins' => $expected,
            'actual_check_ins' => $actual,
            'coverage_percent' => $expected > 0 ? round($actual / $expected * 100, 1) : 0,
            'holidays' => $holidays->map(fn (Holiday $h) => [
                'date_display' => $h->date_display,
                'title' => $h->title,
                'type_label' => $h->type_label,
            ]),
        ]);
    }

    /**
     * Income (sales) against expenses (recorded costs + paid salaries),
     * with the resulting profit.
     */
    public function financial(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        // Income is read from the ledger so bread, flour and miscellaneous
        // receipts are all counted, and counted the same way everywhere.
        $incomeBreakdown = Ledger::incomeBreakdown($from, $to);
        $income = $incomeBreakdown['total'];

        $expensesByCategory = Expense::whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy('category')
            ->map(fn ($group, $category) => [
                'category' => $category,
                'label' => Expense::categoryLabels()[$category] ?? $category,
                'amount' => round((float) $group->sum('amount'), 2),
                'amount_formatted' => Money::format($group->sum('amount')),
                'count' => $group->count(),
            ])
            ->values();

        // Read through the Ledger, like every other figure, so this report
        // cannot drift from the trend chart or the profit split if what
        // counts as a cost ever changes.
        $recordedExpenses = Ledger::recordedExpenses($from, $to);

        // Salaries are tracked separately so they are not double-counted with
        // an "expense" row unless the admin also entered one.
        $paidSalaries = Ledger::paidSalaries($from, $to);

        $unpaidSalaries = (float) SalaryPayment::unpaid()->sum('net_amount');

        $totalExpenses = $recordedExpenses + $paidSalaries;
        $profit = $income - $totalExpenses;

        // The flour the sold bread was baked from, costed as it is consumed
        // rather than on whatever day a purchase was recorded.
        $costOfGoods = Ledger::costOfGoodsSold($from, $to);

        // Flour bought is the same money as flour baked, recognised at a
        // different moment; adding both charges the shop twice for one
        // sack. The arithmetic lives in Ledger so this screen and the
        // dashboard cannot answer the same question differently — which
        // they did, until they were made to share it.
        $flourPurchases = Ledger::flourPurchases($from, $to);
        $operatingExpenses = Ledger::operatingExpenses($from, $to);
        $grossProfit = Ledger::grossProfit($from, $to);
        $netProfit = round($grossProfit - $operatingExpenses, 2);

        return $this->success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'from_jalali' => Jalali::date($from),
            'to_jalali' => Jalali::date($to),
            'currency' => Money::currency(),
            'currency_label' => Money::label(),
            'income' => array_merge($incomeBreakdown, [
                // Kept for the mobile app, which reads `sales` as the total.
                'sales' => round($income, 2),
                'sales_formatted' => Money::format($income),
                'sales_count' => Sale::whereBetween('created_at', [$from, $to])->count(),
                'flour_sales_count' => FlourSale::whereBetween('sold_on', [
                    $from->toDateString(), $to->toDateString(),
                ])->count(),
            ]),
            // Empty while nothing is drawn against the shares, which the
            // app already reads as "do not draw this section" — the two
            // brothers' holding is real, but showing each of them owed a
            // share of a profit nobody has taken, out of a profit with no
            // wages in it, is two wrong numbers in one row.
            'profit_split' => config('bakery.partner_drawings')
                ? BakeryShare::splitFor($from, $to)
                : ['holders' => [], 'total' => 0],
            'expenses' => [
                'recorded' => round($recordedExpenses, 2),
                'recorded_formatted' => Money::format($recordedExpenses),
                'salaries_paid' => round($paidSalaries, 2),
                'salaries_paid_formatted' => Money::format($paidSalaries),
                'total' => round($totalExpenses, 2),
                'total_formatted' => Money::format($totalExpenses),
                'by_category' => $expensesByCategory,
            ],
            'profit' => [
                'amount' => round($profit, 2),
                'formatted' => Money::format($profit),
                'is_positive' => $profit >= 0,
                'margin_percent' => $income > 0 ? round($profit / $income * 100, 1) : 0,
            ],
            // The proper statement, beside the cash figure rather than in
            // its place. `profit` above is money in less money out, and the
            // partners' split is paid on it — changing what it means is the
            // owner's call, not a report's. This block adds what that figure
            // never knew: the flour the bread was baked from.
            'profit_and_loss' => [
                'income' => round($income, 2),
                'income_formatted' => Money::format($income),
                'cost_of_goods' => round($costOfGoods, 2),
                'cost_of_goods_formatted' => Money::format($costOfGoods),
                'gross_profit' => round($grossProfit, 2),
                'gross_profit_formatted' => Money::format($grossProfit),
                'gross_margin_percent' => $income > 0 ? round($grossProfit / $income * 100, 1) : 0,
                'operating_expenses' => round($operatingExpenses, 2),
                'operating_expenses_formatted' => Money::format($operatingExpenses),
                // Shown so the difference from the cash figure above is
                // legible rather than mysterious: this is the flour that
                // moved out of overheads and into cost of goods.
                'flour_purchases_excluded' => $flourPurchases,
                'flour_purchases_excluded_formatted' => Money::format($flourPurchases),
                'net_profit' => round($netProfit, 2),
                'net_profit_formatted' => Money::format($netProfit),
                'net_margin_percent' => $income > 0 ? round($netProfit / $income * 100, 1) : 0,
                'is_positive' => $netProfit >= 0,
            ],
            'outstanding_salaries' => [
                'amount' => round($unpaidSalaries, 2),
                'formatted' => Money::format($unpaidSalaries),
                'count' => SalaryPayment::unpaid()->count(),
            ],
            // A figure on its own says nothing about whether the shop is
            // doing better or worse. The same span immediately before is
            // the comparison the owner makes in their head anyway.
            'compared_to_previous' => $this->comparison($from, $to),
        ]);
    }

    /**
     * The same length of time immediately before this window, and how the
     * headline figures moved between them.
     *
     * A month is compared against the month before, a week against the week
     * before, and a single day against yesterday — whatever range was asked
     * for, shifted back by its own length.
     */
    private function comparison(Carbon $from, Carbon $to): array
    {
        // Whole days, counted from midnight to midnight: `to` is the end of
        // its day, so measuring it directly gives 1.99… for a single day and
        // a window one day too long. Inclusive, so one day counts as one.
        $days = (int) $from->copy()->startOfDay()
            ->diffInDays($to->copy()->startOfDay()) + 1;

        $previousTo = $from->copy()->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();

        $income = Ledger::totalIncome($previousFrom, $previousTo);
        $expenses = Ledger::totalExpenses($previousFrom, $previousTo);
        $profit = $income - $expenses;

        $nowIncome = Ledger::totalIncome($from, $to);
        $nowExpenses = Ledger::totalExpenses($from, $to);
        $nowProfit = $nowIncome - $nowExpenses;

        return [
            'from' => $previousFrom->toDateString(),
            'to' => $previousTo->toDateString(),
            'from_jalali' => Jalali::date($previousFrom),
            'to_jalali' => Jalali::date($previousTo),
            'days' => $days,
            'income' => $this->movement($nowIncome, $income),
            'expenses' => $this->movement($nowExpenses, $expenses),
            'profit' => $this->movement($nowProfit, $profit),
        ];
    }

    /**
     * One figure against its predecessor.
     *
     * The percentage is left null rather than shown as a jump from nothing
     * when the previous period was empty: a first week of trading has not
     * improved by infinity, and saying so would be nonsense on the screen.
     */
    private function movement(float $now, float $before): array
    {
        $change = round($now - $before, 2);

        return [
            'now' => round($now, 2),
            'now_formatted' => Money::format($now),
            'before' => round($before, 2),
            'before_formatted' => Money::format($before),
            'change' => $change,
            'change_formatted' => Money::format(abs($change)),
            'percent' => abs($before) > 0.01
                ? round($change / abs($before) * 100, 1)
                : null,
            'direction' => match (true) {
                $change > 0.01 => 'up',
                $change < -0.01 => 'down',
                default => 'flat',
            },
        ];
    }

    /**
     * Money owed to the bakery, split into what this Jalali month created and
     * what was carried in from earlier months — an old debt needs chasing in
     * a way this month's does not.
     */
    public function debts(Request $request): JsonResponse
    {
        [$monthStart, $monthEnd] = Jalali::currentMonthRange();

        $outstanding = Sale::outstanding()
            ->with(['customer:id,name,type', 'user:id,name'])
            ->orderBy('created_at')
            ->get();

        $thisMonth = $outstanding->filter(
            fn (Sale $s) => $s->created_at->between($monthStart, $monthEnd)
        );
        $previous = $outstanding->reject(
            fn (Sale $s) => $s->created_at->between($monthStart, $monthEnd)
        );

        return $this->success([
            'currency_label' => Money::label(),
            'month_label' => Jalali::monthLabel($monthStart),
            'total' => [
                'amount' => round((float) $outstanding->sum('amount'), 2),
                'formatted' => Money::format($outstanding->sum('amount')),
                'count' => $outstanding->count(),
            ],
            'this_month' => [
                'amount' => round((float) $thisMonth->sum('amount'), 2),
                'formatted' => Money::format($thisMonth->sum('amount')),
                'count' => $thisMonth->count(),
            ],
            'previous_months' => [
                'amount' => round((float) $previous->sum('amount'), 2),
                'formatted' => Money::format($previous->sum('amount')),
                'count' => $previous->count(),
                // The oldest debt is the one most worth chasing.
                'oldest_date' => AppCalendar::date($previous->first()?->created_at),
            ],
            'by_customer' => $outstanding
                ->groupBy(fn (Sale $s) => $s->customer_id ?? 0)
                ->map(fn ($group) => [
                    'customer' => $group->first()->customer?->name ?? 'بدون مشتری',
                    'count' => $group->count(),
                    'amount' => round((float) $group->sum('amount'), 2),
                    'formatted' => Money::format($group->sum('amount')),
                    'oldest_date' => AppCalendar::date($group->first()->created_at),
                ])
                ->sortByDesc('amount')
                ->values(),
        ]);
    }

    /**
     * Day-by-day income and expense series, for charting a trend.
     */
    public function financialTrend(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $days = collect();
        $cursor = $from->copy()->startOfDay();

        // Guard against an unbounded range blowing up the response.
        while ($cursor->lte($to) && $days->count() < 120) {
            $date = $cursor->toDateString();

            [$income, $expense] = Ledger::dailyTotals($cursor);

            $days->push([
                'date' => $date,
                'date_jalali' => Jalali::date($cursor),
                'income' => round($income, 2),
                'expense' => round($expense, 2),
                'profit' => round($income - $expense, 2),
            ]);

            $cursor->addDay();
        }

        return $this->success([
            'currency_label' => Money::label(),
            'days' => $days,
        ]);
    }

    /**
     * Payroll summary for a period: who is owed what, and what has been paid.
     */
    public function payroll(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $payments = SalaryPayment::with('user:id,name')
            ->whereBetween('period_start', [$from->toDateString(), $to->toDateString()])
            ->get();

        return $this->success([
            'from_jalali' => Jalali::date($from),
            'to_jalali' => Jalali::date($to),
            'currency_label' => Money::label(),
            'total_net' => round((float) $payments->sum('net_amount'), 2),
            'total_net_formatted' => Money::format($payments->sum('net_amount')),
            'paid' => round((float) $payments->where('paid_on', '!=', null)->sum('net_amount'), 2),
            'unpaid' => round((float) $payments->where('paid_on', null)->sum('net_amount'), 2),
            'by_employee' => $payments->groupBy('user_id')->map(fn ($group) => [
                'employee' => $group->first()->user?->name,
                'periods' => $group->count(),
                'net_amount' => round((float) $group->sum('net_amount'), 2),
                'net_amount_formatted' => Money::format($group->sum('net_amount')),
                'unpaid' => round((float) $group->where('paid_on', null)->sum('net_amount'), 2),
            ])->values(),
        ]);
    }

    /**
     * Income and cost read a day, a week or a month at a time.
     *
     * The same figures the financial report totals, only cut into the
     * buckets the admin asked for, so a run of days can be read as a
     * trend instead of re-running the report date by date.
     */
    public function financialSeries(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $granularity = PeriodBuckets::normalise($request->query('granularity'));

        $rows = ReportSeries::financial($from, $to, $granularity);

        return $this->success([
            'granularity' => $granularity,
            'granularity_label' => PeriodBuckets::label($granularity),
            'from_jalali' => Jalali::date($from),
            'to_jalali' => Jalali::date($to),
            'currency_label' => Money::label(),
            'totals' => [
                'income' => round((float) $rows->sum('income'), 2),
                'expense' => round((float) $rows->sum('expense'), 2),
                'profit' => round((float) $rows->sum('profit'), 2),
            ],
            'rows' => $rows,
        ]);
    }

    /**
     * What the shop got through, a day, a week or a month at a time.
     */
    public function consumptionSeries(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $granularity = PeriodBuckets::normalise($request->query('granularity'));

        $rows = ReportSeries::consumption($from, $to, $granularity);

        return $this->success([
            'granularity' => $granularity,
            'granularity_label' => PeriodBuckets::label($granularity),
            'from_jalali' => Jalali::date($from),
            'to_jalali' => Jalali::date($to),
            'totals' => [
                'bags_kneaded' => round((float) $rows->sum('bags_kneaded'), 2),
                'flour_used_kg' => round((float) $rows->sum('flour_used_kg'), 3),
                'flour_sold_kg' => round((float) $rows->sum('flour_sold_kg'), 3),
                'salt_kg' => round((float) $rows->sum('salt_kg'), 3),
            ],
            'rows' => $rows,
        ]);
    }

    /**
     * Accepts Jalali (۱۴۰۵/۰۵/۰۳) or Gregorian dates; defaults to today.
     */
    private function range(Request $request): array
    {
        $from = $this->parseDate($request->query('from'))?->startOfDay()
            ?? now()->startOfDay();

        $to = $this->parseDate($request->query('to'))?->endOfDay()
            ?? now()->endOfDay();

        return [$from, $to];
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        return Jalali::parseFlexible($value);
    }

    private function flourBalance(): float
    {
        return InventoryItem::ofKey(InventoryItem::FLOUR)->balance;
    }
}
