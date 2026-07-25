<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\FlourStockMovement;
use App\Models\Holiday;
use App\Models\Sale;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return $this->success([
            'today' => [
                'dough_bags' => (int) DoughEntry::whereDate('created_at', $today)->sum('bag_count'),
                'chane_count' => (int) ChaneEntry::whereDate('created_at', $today)->sum('chane_count'),
                'sales_count' => Sale::whereDate('created_at', $today)->count(),
                'sales_amount' => round((float) Sale::whereDate('created_at', $today)->sum('amount'), 2),
                'attendance_count' => Attendance::where('date', $today)->count(),
            ],
            'queues' => [
                'pending_dough' => DoughEntry::pending()->count(),
                'pending_chane' => ChaneEntry::pending()->count(),
            ],
            'staff' => [
                'total' => User::count(),
                'active' => User::where('is_active', true)->count(),
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
            'total_spray_flour_kg' => round((float) $chane->sum('spray_flour_kg'), 2),
        ]);
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
            'flour_in_kg' => round((float) FlourStockMovement::where('type', 'in')
                ->whereBetween('created_at', [$from, $to])->sum('amount_kg'), 2),
            'flour_out_kg' => round((float) FlourStockMovement::where('type', 'out')
                ->whereBetween('created_at', [$from, $to])->sum('amount_kg'), 2),
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

        $records = Attendance::with('user:id,name')
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

        $activeStaff = User::where('is_active', true)->count();
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

        $income = (float) Sale::whereBetween('created_at', [$from, $to])->sum('amount');

        $expensesByCategory = Expense::whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy('category')
            ->map(fn ($group, $category) => [
                'category' => $category,
                'label' => Expense::CATEGORIES[$category] ?? $category,
                'amount' => round((float) $group->sum('amount'), 2),
                'amount_formatted' => Money::format($group->sum('amount')),
                'count' => $group->count(),
            ])
            ->values();

        $recordedExpenses = (float) Expense::whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->sum('amount');

        // Salaries are tracked separately so they are not double-counted with
        // an "expense" row unless the admin also entered one.
        $paidSalaries = (float) SalaryPayment::paid()
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->sum('net_amount');

        $unpaidSalaries = (float) SalaryPayment::unpaid()->sum('net_amount');

        $totalExpenses = $recordedExpenses + $paidSalaries;
        $profit = $income - $totalExpenses;

        return $this->success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'from_jalali' => Jalali::date($from),
            'to_jalali' => Jalali::date($to),
            'currency' => Money::currency(),
            'currency_label' => Money::label(),
            'income' => [
                'sales' => round($income, 2),
                'sales_formatted' => Money::format($income),
                'sales_count' => Sale::whereBetween('created_at', [$from, $to])->count(),
            ],
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
            'outstanding_salaries' => [
                'amount' => round($unpaidSalaries, 2),
                'formatted' => Money::format($unpaidSalaries),
                'count' => SalaryPayment::unpaid()->count(),
            ],
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

            $income = (float) Sale::whereDate('created_at', $date)->sum('amount');
            $expense = (float) Expense::whereDate('spent_on', $date)->sum('amount')
                + (float) SalaryPayment::paid()->whereDate('paid_on', $date)->sum('net_amount');

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

    private function parseDate(?string $value): ?\Illuminate\Support\Carbon
    {
        if (blank($value)) {
            return null;
        }

        return Jalali::parseFlexible($value);
    }

    private function flourBalance(): float
    {
        $in = (float) FlourStockMovement::where('type', 'in')->sum('amount_kg');
        $out = (float) FlourStockMovement::where('type', 'out')->sum('amount_kg');

        return round($in - $out, 2);
    }
}
