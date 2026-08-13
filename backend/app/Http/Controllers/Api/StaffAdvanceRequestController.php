<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffAdvance;
use App\Models\StaffAdvanceRequest;
use App\Support\AppCalendar;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asking for pay early, which until now happened in the doorway and left
 * no record of who asked, for how much, or what was said back.
 */
class StaffAdvanceRequestController extends Controller
{
    use ApiResponse;

    /** Ask. Always for oneself — nobody asks on another person's behalf. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();

        // One open request at a time: a second one is the same conversation
        // twice, and whoever answers cannot tell which is meant.
        $open = StaffAdvanceRequest::where('user_id', $user->id)->pending()->first();

        if ($open) {
            return $this->error(
                'یک درخواست در انتظار پاسخ دارید. تا تعیین تکلیف آن نمی‌توانید درخواست تازه بدهید.',
                409,
            );
        }

        $advanceRequest = StaffAdvanceRequest::create([
            'user_id' => $user->id,
            // Typed in the shop's display unit, stored in Toman.
            'amount' => Money::toToman($data['amount']),
            'reason' => $data['reason'] ?? null,
        ]);

        return $this->success(
            $this->payload($advanceRequest),
            'درخواست شما ثبت شد و برای مدیر ارسال شد.',
            201,
        );
    }

    /** What I have asked for, and what came back. */
    public function mine(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $requests = StaffAdvanceRequest::where('user_id', $userId)
            ->with('decidedBy:id,name')
            ->latest('id')
            ->get()
            ->map(fn (StaffAdvanceRequest $r) => $this->payload($r));

        return $this->success([
            'requests' => $requests,
            'has_pending' => StaffAdvanceRequest::where('user_id', $userId)
                ->pending()->exists(),
        ]);
    }

    /** Withdraw one nobody has answered yet. */
    public function destroy(Request $request, StaffAdvanceRequest $advanceRequest): JsonResponse
    {
        if ($advanceRequest->user_id !== $request->user()->id) {
            return $this->error('این درخواست متعلق به شما نیست.', 403);
        }

        if (! $advanceRequest->is_pending) {
            return $this->error('به این درخواست پاسخ داده شده و قابل حذف نیست.', 409);
        }

        $advanceRequest->delete();

        return $this->success(null, 'درخواست پس گرفته شد.');
    }

    // ------------------------------------------------------ for the manager

    public function index(Request $request): JsonResponse
    {
        $requests = StaffAdvanceRequest::with(['user:id,name', 'decidedBy:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('status') === null, fn ($q) => $q->pending())
            ->latest('id')
            ->paginate(20)
            ->through(fn (StaffAdvanceRequest $r) => $this->payload($r));

        return $this->success($requests);
    }

    public function approve(Request $request, StaffAdvanceRequest $advanceRequest): JsonResponse
    {
        $data = $request->validate([
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $advanceRequest->is_pending) {
            return $this->error('به این درخواست قبلاً پاسخ داده شده است.', 409);
        }

        $advance = $advanceRequest->approve(
            $request->user(),
            $data['bank_account_id'] ?? null,
            $data['note'] ?? null,
        );

        return $this->success([
            'request' => $this->payload($advanceRequest->fresh(['user:id,name', 'decidedBy:id,name'])),
            'advance_id' => $advance->id,
            // What it now means for that person's pay, said once here so the
            // manager sees the consequence at the moment of granting it.
            'outstanding_after' => StaffAdvance::outstandingFor($advanceRequest->user_id),
        ], 'درخواست تأیید شد و علی‌الحساب ثبت شد.');
    }

    public function reject(Request $request, StaffAdvanceRequest $advanceRequest): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $advanceRequest->is_pending) {
            return $this->error('به این درخواست قبلاً پاسخ داده شده است.', 409);
        }

        $advanceRequest->reject($request->user(), $data['note'] ?? null);

        return $this->success(
            $this->payload($advanceRequest->fresh(['user:id,name', 'decidedBy:id,name'])),
            'درخواست رد شد.',
        );
    }

    private function payload(StaffAdvanceRequest $r): array
    {
        return [
            'id' => $r->id,
            'user_id' => $r->user_id,
            'user_name' => $r->user?->name,
            'amount' => (float) $r->amount,
            'amount_formatted' => Money::format((float) $r->amount),
            'reason' => $r->reason,
            'status' => $r->status,
            'status_label' => $r->status_label,
            'is_pending' => $r->is_pending,
            'decided_by_name' => $r->decidedBy?->name,
            'decided_at_label' => AppCalendar::dateTime($r->decided_at),
            'decision_note' => $r->decision_note,
            'staff_advance_id' => $r->staff_advance_id,
            'requested_at_label' => AppCalendar::date($r->created_at),
        ];
    }
}
