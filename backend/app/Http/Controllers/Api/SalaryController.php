<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalaryPayment;
use App\Models\User;
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
            'base_amount' => $data['base_amount'],
            'bonus' => $data['bonus'] ?? 0,
            'deduction' => $data['deduction'] ?? 0,
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

    /** Staff list with their configured monthly pay, to pre-fill the form. */
    public function employees(): JsonResponse
    {
        $users = User::ofCurrentBakery()->where('is_active', true)
            ->get(['id', 'name', 'monthly_salary'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'monthly_salary' => (float) $u->monthly_salary,
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
            'base_amount' => (float) $payment->base_amount,
            'bonus' => (float) $payment->bonus,
            'deduction' => (float) $payment->deduction,
            'net_amount' => (float) $payment->net_amount,
            'net_amount_formatted' => Money::format($payment->net_amount),
            'paid_on' => $payment->paid_on?->toDateString(),
            'paid_on_jalali' => Jalali::date($payment->paid_on),
            'is_paid' => $payment->is_paid,
            'note' => $payment->note,
        ];
    }
}
