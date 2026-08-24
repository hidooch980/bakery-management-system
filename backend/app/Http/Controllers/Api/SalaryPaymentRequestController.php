<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalaryPayment;
use App\Models\SalaryPaymentRequest;
use App\Support\Jalali;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Staff asking to be paid for the month.
 *
 * The shop went three weeks without writing a single payslip and nobody
 * had a way to say so except in person. This is that, in writing, with a
 * date on it.
 */
class SalaryPaymentRequestController extends Controller
{
    use ApiResponse;

    /** Everything waiting, for whoever pays the wages. */
    public function index(Request $request): JsonResponse
    {
        $rows = SalaryPaymentRequest::with(['user:id,name,monthly_salary', 'decidedBy:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest('created_at')
            ->paginate(30)
            ->through(fn (SalaryPaymentRequest $r) => $this->payload($r));

        return $this->success($rows);
    }

    /** The signed-in person's own. */
    public function mine(Request $request): JsonResponse
    {
        $rows = SalaryPaymentRequest::where('user_id', $request->user()->id)
            ->latest('created_at')
            ->take(12)
            ->get()
            ->map(fn (SalaryPaymentRequest $r) => $this->payload($r));

        return $this->success($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Optional, and only ever a note. The amount is not asked for:
            // the wage is what was agreed, less what has been drawn, and
            // inviting a figure would start a negotiation over a number the
            // system already knows.
            'note' => ['nullable', 'string', 'max:300'],
            'period_start' => ['nullable', 'string', 'max:20'],
        ]);

        $user = $request->user();

        $periodStart = Jalali::parseFlexible($data['period_start'] ?? null)
            ?? Jalali::currentMonthRange()[0];

        if ($user->monthly_salary === null) {
            throw ValidationException::withMessages([
                'user' => ['حقوق ماهانهٔ شما هنوز ثبت نشده. از مدیر بخواهید ثبتش کند.'],
            ]);
        }

        // Already paid for that month, so there is nothing to ask for. Said
        // plainly rather than accepting a request that would sit unanswered
        // for ever because it was answered before it was made.
        $paid = SalaryPayment::where('user_id', $user->id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->exists();

        if ($paid) {
            return $this->error('حقوق این دوره قبلاً پرداخت شده است.', 409);
        }

        $open = SalaryPaymentRequest::where('user_id', $user->id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->pending()
            ->exists();

        if ($open) {
            return $this->error('درخواست شما برای این دوره ثبت شده و در انتظار پاسخ است.', 409);
        }

        $created = SalaryPaymentRequest::create([
            'user_id' => $user->id,
            'period_start' => $periodStart,
            'period_label' => Jalali::monthLabel($periodStart),
            'note' => $data['note'] ?? null,
        ]);

        return $this->success($this->payload($created->fresh('user')), 'درخواست شما ثبت شد.', 201);
    }

    /** Taking it back, while nobody has answered it yet. */
    public function destroy(Request $request, SalaryPaymentRequest $salaryRequest): JsonResponse
    {
        if ($salaryRequest->user_id !== $request->user()->id) {
            return $this->error('این درخواست مال شما نیست.', 403);
        }

        if (! $salaryRequest->is_pending) {
            return $this->error('به این درخواست پاسخ داده شده و پس گرفته نمی‌شود.', 409);
        }

        $salaryRequest->delete();

        return $this->success(null, 'درخواست پس گرفته شد.');
    }

    /**
     * Turning one down, with a reason.
     *
     * There is no matching approve: paying the person for that month is
     * what approval means, and it happens through the pay sheet where the
     * figures are on screen. An approve button here would write a wage
     * nobody had looked at.
     */
    public function reject(Request $request, SalaryPaymentRequest $salaryRequest): JsonResponse
    {
        $data = $request->validate([
            'decision_note' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        if (! $salaryRequest->is_pending) {
            return $this->error('به این درخواست قبلاً پاسخ داده شده است.', 409);
        }

        $salaryRequest->reject($request->user(), $data['decision_note']);

        return $this->success($this->payload($salaryRequest->fresh('user')), 'درخواست رد شد.');
    }

    private function payload(SalaryPaymentRequest $r): array
    {
        $owed = $r->estimatedNet();

        return [
            'id' => $r->id,
            'user' => $r->relationLoaded('user') ? $r->user?->only(['id', 'name']) : null,
            'period_label' => $r->period_label,
            'period_start_jalali' => Jalali::date($r->period_start),
            'status' => $r->status,
            'status_label' => $r->status_label,
            'note' => $r->note,
            'days_waiting' => $r->days_waiting,
            // What it would come to if it were paid today, so whoever is
            // looking at the list knows what they are being asked for. It
            // is a forecast and nothing is stored from it.
            'estimated_net' => $owed === null ? null : Money::convert($owed),
            'estimated_net_formatted' => $owed === null ? null : Money::format($owed),
            'decision_note' => $r->decision_note,
            'decided_by' => $r->relationLoaded('decidedBy') ? $r->decidedBy?->name : null,
            'requested_on_jalali' => Jalali::date($r->created_at),
        ];
    }
}
