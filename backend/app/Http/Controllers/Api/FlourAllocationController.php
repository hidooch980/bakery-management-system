<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlourAllocation;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FlourAllocationController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $allocations = FlourAllocation::with('periods')
            ->orderByDesc('month_start')
            ->paginate(12)
            ->through(fn (FlourAllocation $a) => $this->payload($a));

        return $this->success($allocations);
    }

    /** The allocation covering today, with per-period usage. */
    public function current(): JsonResponse
    {
        $allocation = FlourAllocation::forDate(now());

        if (! $allocation) {
            return $this->success(null, 'برای این بازه سهمیه‌ای تعریف نشده است.');
        }

        return $this->success($this->payload($allocation, now()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month_start' => ['required', 'string', 'max:20'],
            // Quotas are issued in sacks; the weight is derived from them.
            'total_bags' => ['required', 'numeric', 'min:0'],
            // Flour carried over from earlier periods.
            'carryover_bags' => ['nullable', 'numeric', 'min:0'],
            'carryover_note' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $monthStart = Jalali::parseFlexible($data['month_start']);

        if ($monthStart === null) {
            throw ValidationException::withMessages([
                'month_start' => ['تاریخ نامعتبر است. قالب درست: ۱۴۰۵/۰۵/۰۱'],
            ]);
        }

        if (FlourAllocation::whereDate('month_start', $monthStart->toDateString())->exists()) {
            return $this->error('برای این ماه قبلاً سهمیه ثبت شده است.', 409);
        }

        $allocation = FlourAllocation::create([
            'month_start' => $monthStart,
            'month_label' => Jalali::monthLabel($monthStart),
            'total_bags' => $data['total_bags'],
            'carryover_bags' => $data['carryover_bags'] ?? 0,
            'carryover_note' => $data['carryover_note'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        $allocation->syncPeriods();

        return $this->success($this->payload($allocation->fresh('periods')), 'سهمیه ثبت شد.', 201);
    }

    public function update(Request $request, FlourAllocation $allocation): JsonResponse
    {
        $data = $request->validate([
            'total_bags' => ['sometimes', 'numeric', 'min:0'],
            'carryover_bags' => ['sometimes', 'numeric', 'min:0'],
            'carryover_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $allocation->update($data);

        if (array_key_exists('total_bags', $data)) {
            $allocation->syncPeriods();
        }

        return $this->success($this->payload($allocation->fresh('periods')), 'سهمیه به‌روزرسانی شد.');
    }

    public function destroy(FlourAllocation $allocation): JsonResponse
    {
        $allocation->delete();

        return $this->success(null, 'سهمیه حذف شد.');
    }

    private function payload(FlourAllocation $allocation, $highlightDate = null): array
    {
        $current = $highlightDate ? $allocation->periodFor($highlightDate) : null;

        return [
            'id' => $allocation->id,
            'month_start' => $allocation->month_start?->toDateString(),
            'month_label' => $allocation->month_label,
            'total_bags' => (float) $allocation->total_bags,
            'total_kg' => (float) $allocation->total_kg,
            'carryover_bags' => (float) $allocation->carryover_bags,
            'carryover_kg' => (float) $allocation->carryover_kg,
            'carryover_note' => $allocation->carryover_note,
            'available_bags' => $allocation->available_bags,
            'available_kg' => $allocation->available_kg,
            'bag_weight_kg' => \App\Support\DoughFormula::fromBakery()->bagWeightKg,
            'note' => $allocation->note,
            'current_period_number' => $current?->period_number,
            'periods' => $allocation->periods->map(fn ($p) => [
                'number' => $p->period_number,
                'label' => $p->label,
                'starts_on' => $p->starts_on?->toDateString(),
                'ends_on' => $p->ends_on?->toDateString(),
                'starts_on_display' => AppCalendar::date($p->starts_on),
                'ends_on_display' => AppCalendar::date($p->ends_on),
                'allocated_kg' => (float) $p->allocated_kg,
                'allocated_bags' => $allocation->bagsForPeriod($p),
                'used_kg' => $p->used_kg,
                'remaining_kg' => $p->remaining_kg,
                'usage_percent' => $p->usage_percent,
                'is_over' => $p->is_over,
                'is_current' => $current !== null && $current->id === $p->id,
            ]),
        ];
    }
}
