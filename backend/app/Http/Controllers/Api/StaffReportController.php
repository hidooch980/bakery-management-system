<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalaryPayment;
use App\Models\StaffAdjustment;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Models\WorkStart;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\SameBakery;
use App\Support\StaffCost;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The staff, read as people rather than as rows in four separate reports.
 *
 * Everything here already existed somewhere: pay on the payroll report,
 * lateness on the attendance one, advances on their own screen, output on
 * staff-yield. What did not exist was any way to ask «how is this person
 * doing» and get one answer, or «which of them costs what» and get a
 * list — and those are the two questions an owner actually asks.
 */
class StaffReportController extends Controller
{
    use ApiResponse;

    /**
     * Everybody, one row each, so they can be compared.
     *
     * «مقایسهٔ کارکنان». Four reports side by side is not a comparison;
     * it is four reports, and the owner doing the joining in their head.
     */
    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $people = User::ofCurrentBakery()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $rows = $people
            ->map(fn (User $person) => StaffCost::for($person, $from, $to))
            ->values();

        return $this->success([
            'from_jalali' => Jalali::date($from),
            'to_jalali' => Jalali::date($to),
            'currency_label' => Money::label(),
            'staff' => $rows,
            'total' => round($rows->sum('total'), 2),
            'total_formatted' => Money::format((float) $rows->sum('total')),
            // Said rather than left to be inferred from a column of
            // zeroes: a period with no payslips in it is a period that
            // has not been run, not a month where nobody was paid.
            'note' => $rows->sum('payslip_count') === 0
                ? 'در این بازه هیچ فیش حقوقی ثبت نشده — یعنی حقوق این دوره هنوز صادر نشده است.'
                : null,
        ]);
    }

    /**
     * One person, everything.
     *
     * «یک صفحه برای هر نفر». Their cost, their lateness, their advances
     * and their payslips in one answer, so the owner standing in front of
     * somebody is not navigating between four screens to talk to them.
     */
    public function show(Request $request, User $person): JsonResponse
    {
        $person = SameBakery::or404($person);

        [$from, $to] = $this->range($request);

        $payslips = SalaryPayment::query()
            ->where('user_id', $person->id)
            ->whereBetween('period_start', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('period_start')
            ->get()
            ->map(fn (SalaryPayment $p) => [
                'id' => $p->id,
                'period_label' => $p->period_label,
                'base_formatted' => Money::format((float) $p->base_amount),
                'bonus_formatted' => Money::format((float) $p->bonus),
                'deduction_formatted' => Money::format((float) $p->deduction),
                'net_formatted' => Money::format((float) $p->net_amount),
                'paid_on_label' => $p->paid_on ? AppCalendar::date($p->paid_on) : null,
                'is_paid' => $p->paid_on !== null,
            ]);

        $advances = StaffAdvance::query()
            ->where('user_id', $person->id)
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('paid_on')
            ->get()
            ->map(fn (StaffAdvance $a) => [
                'id' => $a->id,
                'amount_formatted' => Money::format((float) $a->amount),
                'outstanding_formatted' => Money::format($a->outstanding),
                'paid_on_label' => AppCalendar::date($a->paid_on),
                'note' => $a->note,
            ]);

        $late = WorkStart::query()
            ->where('user_id', $person->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('penalty_amount', '>', 0)
            ->orderByDesc('date')
            ->get()
            ->map(fn (WorkStart $w) => [
                'date_label' => AppCalendar::date($w->date),
                'type' => $w->type,
                'late_minutes' => (int) $w->late_minutes,
                'penalty_formatted' => Money::format((float) $w->penalty_amount),
            ]);

        $forgiven = StaffAdjustment::query()
            ->where('user_id', $person->id)
            ->whereNotNull('waived_at')
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('occurred_on')
            ->get()
            ->map(fn (StaffAdjustment $a) => [
                'date_label' => AppCalendar::date($a->occurred_on),
                'amount_formatted' => Money::format((float) $a->amount),
                'reason' => $a->reason,
            ]);

        return $this->success([
            'from_jalali' => Jalali::date($from),
            'to_jalali' => Jalali::date($to),
            'currency_label' => Money::label(),
            'cost' => StaffCost::for($person, $from, $to),
            'payslips' => $payslips,
            'advances' => $advances,
            'late' => $late,
            // Kept visible on purpose. A deduction the owner forgave is
            // the sort of thing that is remembered as «he was never
            // late», and the row is the only record that it happened at
            // all and that somebody decided to let it go.
            'forgiven' => $forgiven,
        ]);
    }

    private function range(Request $request): array
    {
        $from = $this->parseDate($request->query('from'))?->startOfDay()
            ?? now()->startOfMonth()->startOfDay();

        $to = $this->parseDate($request->query('to'))?->endOfDay()
            ?? now()->endOfDay();

        return [$from, $to];
    }

    private function parseDate(?string $value): ?Carbon
    {
        return blank($value) ? null : Jalali::parseFlexible($value);
    }
}
