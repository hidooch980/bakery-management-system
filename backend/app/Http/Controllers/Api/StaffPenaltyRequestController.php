<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffPenaltyRequest;
use App\Models\User;
use App\Support\AppCalendar;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Penalty request: the seller asks, the manager decides, and only an
 * approved fine reaches the payslip (as a penalty StaffAdjustment).
 */
class StaffPenaltyRequestController extends Controller
{
    use ApiResponse;

    private const WITH = ['user:id,name', 'requestedBy:id,name', 'decidedBy:id,name', 'adjustment'];

    /** Who can be named (every active member of staff), and the reasons. */
    public function options(Request $request): JsonResponse
    {
        $staff = User::query()
            // This shop's people only; another shop's staff are not ours to name.
            ->ofCurrentBakery()
            ->where('is_active', true)
            ->where('id', '!=', $request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]);

        return $this->success([
            'staff' => $staff,
            'reasons' => collect(StaffPenaltyRequest::REASONS)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'types' => collect(StaffPenaltyRequest::TYPE_LABELS)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id', Rule::notIn([$request->user()->id])],
            'type' => ['required', Rule::in([StaffPenaltyRequest::FINE, StaffPenaltyRequest::WARNING])],
            'reason' => ['required', Rule::in(array_keys(StaffPenaltyRequest::REASONS))],
            'amount' => ['nullable', 'required_if:type,fine', 'numeric', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if (! User::query()->ofCurrentBakery()->whereKey($data['user_id'])->exists()) {
            return $this->error('این شخص از کارکنان این نانوایی نیست.', 422);
        }

        $penalty = StaffPenaltyRequest::create([
            'user_id' => $data['user_id'],
            'requested_by' => $request->user()->id,
            'type' => $data['type'],
            'reason' => $data['reason'],
            'amount' => $data['type'] === StaffPenaltyRequest::FINE ? Money::toToman($data['amount']) : null,
            'note' => $data['note'] ?? null,
            'occurred_on' => now()->toDateString(),
        ]);

        return $this->success(
            $this->payload($penalty->load(self::WITH)),
            'درخواست تنبیه ثبت شد و برای تأیید مدیر ارسال شد.',
            201,
        );
    }

    /** What this seller has asked for, and what came back. */
    public function mine(Request $request): JsonResponse
    {
        $rows = StaffPenaltyRequest::with(self::WITH)
            ->where('requested_by', $request->user()->id)
            ->latest('id')->limit(100)->get()
            ->map(fn (StaffPenaltyRequest $r) => $this->payload($r));

        return $this->success(['requests' => $rows]);
    }

    public function destroy(Request $request, StaffPenaltyRequest $penaltyRequest): JsonResponse
    {
        if ($penaltyRequest->requested_by !== $request->user()->id) {
            return $this->error('این درخواست متعلق به شما نیست.', 403);
        }
        if (! $penaltyRequest->is_pending) {
            return $this->error('به این درخواست پاسخ داده شده و قابل حذف نیست.', 409);
        }
        $penaltyRequest->delete();

        return $this->success(null, 'درخواست پس گرفته شد.');
    }

    // ------------------------------------------------------ for the manager

    public function index(Request $request): JsonResponse
    {
        $rows = StaffPenaltyRequest::with(self::WITH)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('status') === null, fn ($q) => $q->pending())
            ->latest('id')
            ->paginate(20)
            ->through(fn (StaffPenaltyRequest $r) => $this->payload($r));

        return $this->success($rows);
    }

    public function approve(Request $request, StaffPenaltyRequest $penaltyRequest): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $adjustment = $penaltyRequest->approve(
                $request->user(),
                isset($data['amount']) ? Money::toToman($data['amount']) : null,
                $data['note'] ?? null,
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 409);
        }

        return $this->success([
            'request' => $this->payload($penaltyRequest->fresh(self::WITH)),
            'staff_adjustment_id' => $adjustment->id,
        ], 'تنبیه تأیید و در کسورات ثبت شد.');
    }

    public function reject(Request $request, StaffPenaltyRequest $penaltyRequest): JsonResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ]);

        try {
            $penaltyRequest->reject($request->user(), $data['note']);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 409);
        }

        return $this->success($this->payload($penaltyRequest->fresh(self::WITH)), 'درخواست رد شد.');
    }

    /** Cancel an approved penalty: its adjustment is waived, never deleted. */
    public function cancel(Request $request, StaffPenaltyRequest $penaltyRequest): JsonResponse
    {
        try {
            $penaltyRequest->cancel($request->user());
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 409);
        }

        return $this->success($this->payload($penaltyRequest->fresh(self::WITH)), 'تنبیه لغو شد.');
    }

    private function payload(StaffPenaltyRequest $r): array
    {
        $amount = $r->amount === null ? null : (float) $r->amount;
        $late = $r->lateThatDay();

        return [
            'id' => $r->id,
            'user_id' => $r->user_id,
            'user_name' => $r->user?->name,
            'requested_by_name' => $r->requestedBy?->name,
            'type' => $r->type,
            'type_label' => $r->type_label,
            'reason' => $r->reason,
            'reason_label' => $r->reason_label,
            'amount' => $amount === null ? null : Money::convert($amount),
            'amount_formatted' => $amount === null ? null : Money::format($amount),
            'note' => $r->note,
            'status' => $r->status,
            'status_label' => $r->is_cancelled ? 'لغو شد' : $r->status_label,
            'is_pending' => $r->is_pending,
            'is_cancelled' => $r->is_cancelled,
            'decided_by_name' => $r->decidedBy?->name,
            'decided_at_label' => AppCalendar::dateTime($r->decided_at),
            'decision_note' => $r->decision_note,
            'requested_at_label' => AppCalendar::date($r->created_at),
            // Warn, never block: the late tariff may already dock this day.
            'late_minutes_that_day' => $late,
            'late_warning' => $late === null ? null
                : 'این شخص همین روز '.$late.' دقیقه تأخیر ثبت‌شده دارد؛ جریمهٔ خودکار تأخیر جداگانه کسر می‌شود.',
        ];
    }
}
