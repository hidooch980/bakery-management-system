<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffAdjustment;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Rewards and penalties, recorded on the day rather than recalled at payday.
 *
 * Nothing here moves money. The payslip does that, once, for the net —
 * these only say what the month came to, and the pay sheet opens on their
 * total so the figure is arrived at rather than remembered.
 */
class StaffAdjustmentController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $rows = StaffAdjustment::with(['user:id,name,monthly_salary', 'recordedBy:id,name'])
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->query('status') === 'unsettled', fn ($q) => $q->unsettled())
            ->latest('occurred_on')
            ->latest('id')
            ->paginate(30)
            ->through(fn (StaffAdjustment $a) => $this->payload($a));

        return $this->success($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'kind' => ['required', 'in:'.StaffAdjustment::REWARD.','.StaffAdjustment::PENALTY],
            'basis' => ['required', 'in:'.StaffAdjustment::BY_AMOUNT.','.StaffAdjustment::BY_DAYS.','.StaffAdjustment::BY_NOTE],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'days' => ['nullable', 'numeric', 'min:0.25', 'max:31'],
            'occurred_on' => ['nullable', 'string', 'max:20'],
            // Required, and not by accident. A deduction nobody can explain
            // a month later is one the person it was taken from will
            // dispute, and they will be right to.
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        $this->refuseAMissingFigure($data);

        $person = User::findOrFail($data['user_id']);

        if ($data['basis'] === StaffAdjustment::BY_DAYS && ! $person->monthly_salary) {
            throw ValidationException::withMessages([
                'basis' => ['حقوق ماهانهٔ این کارمند ثبت نشده، پس روز را نمی‌شود به مبلغ تبدیل کرد.'],
            ]);
        }

        $adjustment = StaffAdjustment::create([
            'user_id' => $data['user_id'],
            'recorded_by' => $request->user()?->id,
            'kind' => $data['kind'],
            'basis' => $data['basis'],
            // Typed in the shop's display unit and stored in Toman, like
            // every other figure that crosses this API.
            'amount' => $data['basis'] === StaffAdjustment::BY_AMOUNT
                ? Money::toToman($data['amount'])
                : null,
            'days' => $data['basis'] === StaffAdjustment::BY_DAYS ? $data['days'] : null,
            'occurred_on' => Jalali::parseFlexible($data['occurred_on'] ?? null) ?? now(),
            'reason' => $data['reason'],
        ]);

        return $this->success($this->payload($adjustment->fresh('user')), 'ثبت شد.', 201);
    }

    public function destroy(StaffAdjustment $adjustment): JsonResponse
    {
        if ($adjustment->salary_payment_id !== null) {
            return $this->error('این مورد در فیش حقوقی لحاظ شده و پاک نمی‌شود. اول فیش را اصلاح کنید.', 409);
        }

        $adjustment->delete();

        return $this->success(null, 'حذف شد.');
    }

    /**
     * The month's rewards and penalties for one person, for the pay sheet
     * to open on.
     *
     * Two totals, kept apart, because they land in two different boxes and
     * netting them would hide both.
     */
    public function forPeriod(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'period_start' => ['nullable', 'string', 'max:20'],
        ]);

        $start = Jalali::parseFlexible($data['period_start'] ?? null);
        [$from, $until] = $start
            ? Jalali::monthRangeFor($start)
            : Jalali::currentMonthRange();

        $totals = StaffAdjustment::monthFor((int) $data['user_id'], $from, $until);

        $rows = StaffAdjustment::with('user:id,monthly_salary')
            ->where('user_id', $data['user_id'])
            ->unsettled()
            ->whereBetween('occurred_on', [$from, $until])
            ->orderBy('occurred_on')
            ->get();

        return $this->success([
            'period_label' => Jalali::monthLabel($from),
            'reward_total' => Money::convert($totals['reward']),
            'reward_total_formatted' => Money::format($totals['reward']),
            'penalty_total' => Money::convert($totals['penalty']),
            'penalty_total_formatted' => Money::format($totals['penalty']),
            'count' => $totals['count'],
            'items' => $rows->map(fn (StaffAdjustment $a) => $this->payload($a))->all(),
        ]);
    }

    /**
     * An amount basis needs an amount and a days basis needs days.
     *
     * Left to the nullable rules alone, a reward could be saved with
     * neither and quietly be worth nothing — which is exactly what a
     * note-only entry is, so the two would be indistinguishable on the
     * list and only one of them was meant.
     */
    private function refuseAMissingFigure(array $data): void
    {
        if ($data['basis'] === StaffAdjustment::BY_AMOUNT && ! ($data['amount'] ?? null)) {
            throw ValidationException::withMessages([
                'amount' => ['مبلغ را وارد کنید، یا مبنا را روی «روز» یا «فقط ثبت» بگذارید.'],
            ]);
        }

        if ($data['basis'] === StaffAdjustment::BY_DAYS && ! ($data['days'] ?? null)) {
            throw ValidationException::withMessages([
                'days' => ['تعداد روز را وارد کنید.'],
            ]);
        }
    }

    private function payload(StaffAdjustment $a): array
    {
        return [
            'id' => $a->id,
            'user' => $a->relationLoaded('user') ? $a->user?->only(['id', 'name']) : null,
            'kind' => $a->kind,
            'kind_label' => $a->kind_label,
            'basis' => $a->basis,
            'basis_label' => $a->basis_label,
            'value' => Money::convert($a->value),
            'value_formatted' => $a->is_note_only ? '—' : Money::format($a->value),
            'days' => $a->days === null ? null : (float) $a->days,
            'occurred_on_jalali' => Jalali::date($a->occurred_on),
            'reason' => $a->reason,
            'recorded_by' => $a->relationLoaded('recordedBy') ? $a->recordedBy?->name : null,
            'is_settled' => $a->salary_payment_id !== null,
            'is_note_only' => $a->is_note_only,
        ];
    }
}
