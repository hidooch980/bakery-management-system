<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Money handed to a member of staff before payday.
 *
 * The panel has recorded these since they were built, and payslips have
 * been deducting them; the phone knew nothing about either. Someone who
 * took an advance had no way to see what they had taken or what next
 * month's pay would be short by, which is exactly the person who most
 * needs to know.
 */
class StaffAdvanceController extends Controller
{
    use ApiResponse;

    /** Everyone's advances — for whoever runs the payroll. */
    public function index(Request $request): JsonResponse
    {
        $advances = StaffAdvance::with(['user:id,name', 'recordedBy:id,name'])
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->query('status') === 'outstanding', fn ($q) => $q->outstanding())
            ->latest('paid_on')
            ->latest('id')
            ->paginate(20)
            ->through(fn (StaffAdvance $a) => $this->payload($a));

        return $this->success($advances);
    }

    /** What this person has taken, and what is still to come off their pay. */
    public function mine(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $advances = StaffAdvance::where('user_id', $userId)
            ->latest('paid_on')
            ->latest('id')
            ->get()
            ->map(fn (StaffAdvance $a) => $this->payload($a));

        $outstanding = StaffAdvance::outstandingFor($userId);

        return $this->success([
            'advances' => $advances,
            'outstanding' => $outstanding,
            'outstanding_formatted' => Money::format($outstanding),
            // Said plainly, because the number on its own reads like a debt
            // rather than pay already received.
            'summary' => $outstanding > 0
                ? 'از حقوق بعدی شما '.Money::format($outstanding).' بابت علی‌الحساب کسر می‌شود.'
                : 'علی‌الحساب تسویه‌نشده‌ای ندارید.',
        ]);
    }

    /** What each employee still owes, for the payroll screen. */
    public function outstanding(): JsonResponse
    {
        $rows = User::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (User $u) {
                $owed = StaffAdvance::outstandingFor($u->id);

                return [
                    'user_id' => $u->id,
                    'user_name' => $u->name,
                    'outstanding' => $owed,
                    'outstanding_formatted' => Money::format($owed),
                ];
            })
            ->filter(fn (array $r) => $r['outstanding'] > 0)
            ->values();

        return $this->success([
            'employees' => $rows,
            'total' => round($rows->sum('outstanding'), 2),
            'total_formatted' => Money::format((float) $rows->sum('outstanding')),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'paid_on' => ['nullable', 'string', 'max:20'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $paidOn = array_key_exists('paid_on', $data) && $data['paid_on'] !== null
            ? Jalali::parseFlexible($data['paid_on'])
            : now();

        if ($paidOn === null) {
            throw ValidationException::withMessages([
                'paid_on' => ['تاریخ نامعتبر است. قالب درست: ۱۴۰۵/۰۵/۰۱'],
            ]);
        }

        $advance = StaffAdvance::create([
            'user_id' => $data['user_id'],
            'recorded_by' => $request->user()->id,
            // Typed in the shop's display unit, stored in Toman, like every
            // other amount that comes in through the API.
            'amount' => Money::toToman($data['amount']),
            'paid_on' => $paidOn,
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        return $this->success(
            $this->payload($advance->fresh(['user:id,name', 'recordedBy:id,name'])),
            'علی‌الحساب ثبت شد.',
            201,
        );
    }

    public function destroy(StaffAdvance $advance): JsonResponse
    {
        // Deleting an advance a payslip has already taken back would leave
        // that deduction pointing at nothing, and the employee short.
        if ($advance->recovered > 0) {
            return $this->error(
                'این علی‌الحساب از حقوق کسر شده و قابل حذف نیست.',
                409,
            );
        }

        $advance->delete();

        return $this->success(null, 'علی‌الحساب حذف شد.');
    }

    private function payload(StaffAdvance $a): array
    {
        return [
            'id' => $a->id,
            'user_id' => $a->user_id,
            'user_name' => $a->user?->name,
            'recorded_by_name' => $a->recordedBy?->name,
            'amount' => (float) $a->amount,
            'amount_formatted' => Money::format((float) $a->amount),
            'recovered' => $a->recovered,
            'recovered_formatted' => Money::format($a->recovered),
            'outstanding' => $a->outstanding,
            'outstanding_formatted' => Money::format($a->outstanding),
            'is_settled' => $a->is_settled,
            'paid_on' => $a->paid_on?->toDateString(),
            'paid_on_label' => AppCalendar::date($a->paid_on),
            'note' => $a->note,
        ];
    }
}
