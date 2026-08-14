<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalaryPayment;
use App\Models\StaffAdvance;
use App\Models\StaffAdvanceRequest;
use App\Models\User;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SalaryController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $payments = SalaryPayment::with('user:id,name')
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->query('status') === 'paid', fn ($q) => $q->paid())
            ->when($request->query('status') === 'unpaid', fn ($q) => $q->unpaid())
            ->latest('period_start')
            ->paginate(20)
            ->through(fn (SalaryPayment $p) => $this->payload($p));

        return $this->success($payments);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'period_start' => ['required', 'string', 'max:20'],
            'base_amount' => ['required', 'numeric', 'min:0'],
            'bonus' => ['nullable', 'numeric', 'min:0'],
            'deduction' => ['nullable', 'numeric', 'min:0'],
            'paid_on' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $periodStart = Jalali::parseFlexible($data['period_start']);

        if ($periodStart === null) {
            throw ValidationException::withMessages([
                'period_start' => ['تاریخ دوره نامعتبر است. قالب درست: ۱۴۰۵/۰۵/۰۱'],
            ]);
        }

        // One payment per employee per period keeps the payroll unambiguous.
        $exists = SalaryPayment::where('user_id', $data['user_id'])
            ->whereDate('period_start', $periodStart->toDateString())
            ->exists();

        if ($exists) {
            return $this->error('برای این کارمند در این دوره قبلاً حقوق ثبت شده است.', 409);
        }

        $payment = SalaryPayment::create([
            'user_id' => $data['user_id'],
            'period_start' => $periodStart,
            'period_label' => Jalali::monthLabel($periodStart),
            // Typed in the shop's display unit, stored in Toman. A shop set
            // to Rial was having every payslip saved ten times over.
            'base_amount' => Money::toToman($data['base_amount']),
            'bonus' => Money::toToman($data['bonus'] ?? 0),
            'deduction' => Money::toToman($data['deduction'] ?? 0),
            'paid_on' => Jalali::parseFlexible($data['paid_on'] ?? null),
            'note' => $data['note'] ?? null,
        ]);

        return $this->success($this->payload($payment), 'حقوق ثبت شد.', 201);
    }

    public function update(Request $request, SalaryPayment $salary): JsonResponse
    {
        $data = $request->validate([
            'base_amount' => ['sometimes', 'numeric', 'min:0'],
            'bonus' => ['sometimes', 'numeric', 'min:0'],
            'deduction' => ['sometimes', 'numeric', 'min:0'],
            'paid_on' => ['sometimes', 'nullable', 'string', 'max:20'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if (array_key_exists('paid_on', $data)) {
            $data['paid_on'] = Jalali::parseFlexible($data['paid_on']);
        }

        foreach (['base_amount', 'bonus', 'deduction'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = Money::toToman($data[$field]);
            }
        }

        $salary->update($data);

        return $this->success($this->payload($salary->fresh()), 'حقوق به‌روزرسانی شد.');
    }

    /** Marks an outstanding salary as paid today. */
    public function markPaid(SalaryPayment $salary): JsonResponse
    {
        if ($salary->is_paid) {
            return $this->error('این حقوق قبلاً پرداخت شده است.', 409);
        }

        $salary->update(['paid_on' => now()]);

        return $this->success($this->payload($salary->fresh()), 'پرداخت ثبت شد.');
    }

    public function destroy(SalaryPayment $salary): JsonResponse
    {
        $salary->delete();

        return $this->success(null, 'رکورد حقوق حذف شد.');
    }

    /** The signed-in employee's own payslips. */
    public function mine(Request $request): JsonResponse
    {
        $payments = SalaryPayment::where('user_id', $request->user()->id)
            ->latest('period_start')
            ->paginate(20)
            ->through(fn (SalaryPayment $p) => $this->payload($p));

        return $this->success($payments);
    }

    /**
     * What this person is owed, in one call, for the card on their home
     * screen.
     *
     * Their pay was visible to everyone but them. The one whose month is
     * about to be short is the one who most needs to know by how much, and
     * asking the office is how a wrong figure survives for months.
     *
     * Two different truths are reported rather than one blended one:
     * payslips already issued and not yet paid, which is what the shop owes
     * outright; and what is left of this month's wage after the advances
     * drawn against it, which is a forecast and is labelled as one. Adding
     * them together would produce a number that is neither.
     */
    public function mySummary(Request $request): JsonResponse
    {
        $user = $request->user();

        $salary = $user->monthly_salary === null ? null : (float) $user->monthly_salary;
        $outstanding = StaffAdvance::outstandingFor($user->id);

        $unpaid = SalaryPayment::where('user_id', $user->id)
            ->unpaid()
            ->get(['net_amount']);

        $unpaidTotal = (float) $unpaid->sum('net_amount');

        // Floored at zero: an advance larger than a month's wage is not a
        // negative wage, it is recovered over as many months as it takes —
        // which is what the payslip itself does.
        $remaining = $salary === null ? null : max(0.0, $salary - $outstanding);
        $carriesOver = $salary !== null && $outstanding > $salary;

        return $this->success([
            // Through AppCalendar, not Jalali outright: the shop chooses its
            // calendar, and a card headed with a month it does not use is a
            // label for the wrong month.
            'period_label' => AppCalendar::monthLabel(now()),

            'monthly_salary' => $salary === null ? null : Money::convert($salary),
            'monthly_salary_formatted' => $salary === null ? null : Money::format($salary),

            'advance_outstanding' => Money::convert($outstanding),
            'advance_outstanding_formatted' => Money::format($outstanding),

            'unpaid_payslips_total' => Money::convert($unpaidTotal),
            'unpaid_payslips_total_formatted' => Money::format($unpaidTotal),
            'unpaid_payslips_count' => $unpaid->count(),

            'remaining' => $remaining === null ? null : Money::convert($remaining),
            'remaining_formatted' => $remaining === null ? null : Money::format($remaining),
            'carries_over' => $carriesOver,

            // One open request at a time, so the card can say so instead of
            // offering a button the server is going to refuse.
            'has_pending_request' => StaffAdvanceRequest::where('user_id', $user->id)
                ->where('status', StaffAdvanceRequest::PENDING)
                ->exists(),

            'summary' => $this->summarise($salary, $outstanding, $remaining, $unpaidTotal, $carriesOver),
        ]);
    }

    /**
     * The same figures said in a sentence.
     *
     * A row of numbers is read by whoever already knows what they mean. The
     * person this is for may not, and a wage they cannot read is a wage they
     * still have to ask about.
     */
    private function summarise(
        ?float $salary,
        float $outstanding,
        ?float $remaining,
        float $unpaidTotal,
        bool $carriesOver,
    ): string {
        if ($unpaidTotal > 0) {
            return 'فیش حقوقی پرداخت‌نشده دارید: '.Money::format($unpaidTotal).'.';
        }

        if ($salary === null) {
            return 'حقوق ماهانه شما هنوز ثبت نشده است. از مدیر بخواهید ثبتش کند.';
        }

        // None of these say "this month". This shop issues no payslips, so
        // an advance is not settled at month end — it stands as a debt and
        // is still being deducted long after the month it was taken in.
        // Saying "this month" would blame the current month for money drawn
        // in an earlier one, and promise a settlement that never comes.
        if ($outstanding <= 0) {
            return 'بدهی علی‌الحسابی ندارید.';
        }

        if ($carriesOver) {
            return 'بدهی علی‌الحساب شما از حقوق یک ماه بیشتر است.';
        }

        return 'اگر امروز تسویه شود، '.Money::format($remaining).' به شما می‌رسد.';
    }

    /** Staff list with their configured monthly pay, to pre-fill the form. */
    public function employees(): JsonResponse
    {
        $users = User::ofCurrentBakery()->where('is_active', true)
            ->get(['id', 'name', 'monthly_salary'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'monthly_salary' => Money::convert($u->monthly_salary),
                'monthly_salary_formatted' => Money::format($u->monthly_salary),
            ]);

        return $this->success($users);
    }

    private function payload(SalaryPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'user' => $payment->relationLoaded('user') ? $payment->user?->only(['id', 'name']) : null,
            'period_start' => $payment->period_start?->toDateString(),
            'period_label' => $payment->period_label,
            // In the display unit, matching what was typed. The stored
            // figures are Toman; handing those back put the wrong numbers
            // into a Rial shop's edit form.
            'base_amount' => Money::convert($payment->base_amount),
            'bonus' => Money::convert($payment->bonus),
            'deduction' => Money::convert($payment->deduction),
            'net_amount' => Money::convert($payment->net_amount),
            'net_amount_formatted' => Money::format($payment->net_amount),
            'paid_on' => $payment->paid_on?->toDateString(),
            'paid_on_jalali' => Jalali::date($payment->paid_on),
            'is_paid' => $payment->is_paid,
            'note' => $payment->note,
        ];
    }
}
