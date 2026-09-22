<?php

namespace App\Support;

use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Models\StaffAdjustment;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Models\WorkStart;
use Illuminate\Support\Carbon;

/**
 * What one person actually costs the shop over a period.
 *
 * «هزینهٔ واقعی هر نفر». The payroll report has only ever shown net pay,
 * which is the last line of a payslip rather than the cost of employing
 * somebody: it is already net of the advances they took, and it says
 * nothing about money that left the shop for them and has not come back.
 *
 * So the figure is built from what the books actually record, not from a
 * model of what an employee ought to cost:
 *
 *   • wages, gross, before anything was taken off;
 *   • bonuses added on the payslip;
 *   • deductions taken off it, which reduce the cost and are shown doing
 *     so rather than quietly netted away;
 *   • advances paid in the period that no payslip has recovered — money
 *     out of the till with nothing yet against it;
 *   • for a seller, money the shop is short because their till did not
 *     balance and the gap was never settled.
 *
 * Every one of those is a row somebody can go and look at, which is the
 * point: a cost figure nobody can check is a number people argue with.
 */
class StaffCost
{
    /**
     * @return array<string, mixed>
     */
    public static function for(User $person, Carbon $from, Carbon $to): array
    {
        $payslips = SalaryPayment::query()
            ->where('user_id', $person->id)
            ->whereBetween('period_start', [$from->toDateString(), $to->toDateString()])
            ->get();

        $gross = round((float) $payslips->sum('base_amount'), 2);
        $bonus = round((float) $payslips->sum('bonus'), 2);
        $deduction = round((float) $payslips->sum('deduction'), 2);
        $breadDeduction = round((float) $payslips->sum('bread_deduction'), 2);
        $net = round((float) $payslips->sum('net_amount'), 2);
        $unpaid = round((float) $payslips->whereNull('paid_on')->sum('net_amount'), 2);

        // Advances handed over inside the period. Only the part no
        // payslip has taken back counts as cost: the rest is pay already
        // in the gross figure above, and counting both is counting the
        // same money twice.
        $advances = StaffAdvance::query()
            ->where('user_id', $person->id)
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->get();

        $advancePaid = round((float) $advances->sum('amount'), 2);
        $advanceOpen = round($advances->sum(fn (StaffAdvance $a) => $a->outstanding), 2);

        // A gap between the money a seller handed over and the bread
        // their sales were worth, still unsettled. Real money the shop
        // does not have; shown apart from wages because it is not pay.
        $shortfall = round((float) Sale::query()
            ->where('user_id', $person->id)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereNull('shortfall_settled_on')
            ->where('shortfall_count', '>', 0)
            ->sum('shortfall_amount'), 2);

        $lateDays = WorkStart::query()
            ->where('user_id', $person->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('penalty_amount', '>', 0)
            // One day, however many activities were late on it — the
            // tariff already charges a day once.
            ->distinct()
            ->count('date');

        $waived = round((float) StaffAdjustment::query()
            ->where('user_id', $person->id)
            ->whereNotNull('waived_at')
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
            ->sum('amount'), 2);

        // Gross plus what was added, less what was taken off, plus money
        // out that has not come back. Written as one line so the answer
        // and the arithmetic cannot drift apart.
        $total = round(
            $gross + $bonus - $deduction - $breadDeduction + $advanceOpen + $shortfall,
            2,
        );

        return [
            'user_id' => $person->id,
            'name' => $person->name,
            'roles' => $person->getRoleNames()->all(),
            'gross' => $gross,
            'gross_formatted' => Money::format($gross),
            'bonus' => $bonus,
            'bonus_formatted' => Money::format($bonus),
            'deduction' => $deduction,
            'deduction_formatted' => Money::format($deduction),
            'bread_deduction' => $breadDeduction,
            'bread_deduction_formatted' => Money::format($breadDeduction),
            'net_paid' => $net,
            'net_paid_formatted' => Money::format($net),
            'unpaid' => $unpaid,
            'unpaid_formatted' => Money::format($unpaid),
            'advance_paid' => $advancePaid,
            'advance_paid_formatted' => Money::format($advancePaid),
            'advance_open' => $advanceOpen,
            'advance_open_formatted' => Money::format($advanceOpen),
            'shortfall' => $shortfall,
            'shortfall_formatted' => Money::format($shortfall),
            'late_days' => $lateDays,
            'waived' => $waived,
            'waived_formatted' => Money::format($waived),
            'payslip_count' => $payslips->count(),
            'total' => $total,
            'total_formatted' => Money::format($total),
            // The arithmetic, in words, for the same reason the seller's
            // breakdown states its rule: the subtractions are what people
            // query, and a figure nobody can check is one they argue with.
            'rule' => 'حقوق ناخالص + پاداش − کسورات − نان برده‌شده'
                .' + علی‌الحساب بازنگشته + کسری تسویه‌نشده = هزینهٔ واقعی.',
        ];
    }
}
