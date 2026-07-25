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
            'total_kg' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $monthStart = Jalali::parse($data['month_start']);

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
            'total_kg' => $data['total_kg'],
            'note' => $data['note'] ?? null,
        ]);

        $allocation->syncPeriods();

        return $this->success($this->payload($allocation->fresh('periods')), 'سهمیه ثبت شد.', 201);
    }

    public function update(Request $request, FlourAllocation $allocation): JsonResponse
    {
        $data = $request->validate([
            'total_kg' => ['sometimes', 'numeric', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $allocation->update($data);

        if (array_key_exists('total_kg', $data)) {
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
            'total_kg' => (float) $allocation->total_kg,
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
                'used_kg' => $p->used_kg,
                'remaining_kg' => $p->remaining_kg,
                'usage_percent' => $p->usage_percent,
                'is_over' => $p->is_over,
                'is_current' => $current !== null && $current->id === $p->id,
            ]),
        ];
    }
}
