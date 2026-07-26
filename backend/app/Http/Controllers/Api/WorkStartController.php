<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkStart;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\LatePenalty;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkStartController extends Controller
{
    use ApiResponse;

    /** Today's start board for both shaping and baking. */
    public function today(): JsonResponse
    {
        return $this->success(WorkStart::todayBoard());
    }

    /**
     * The late-start tariff on its own, readable by every member of staff so
     * the rules are announced rather than discovered when money is deducted.
     */
    public function rules(): JsonResponse
    {
        return $this->success(array_merge(LatePenalty::tariff(), [
            'deadlines' => collect(WorkStart::TYPES)->map(fn ($label, $type) => [
                'type' => $type,
                'label' => $label,
                'deadline' => WorkStart::deadlineFor($type),
            ])->values(),
            'month_summary' => WorkStart::monthSummary(),
        ]));
    }

    /**
     * Ticks the start of shaping or baking. Recording it a second time is
     * not an error — it returns the original tick, so a double tap cannot
     * overwrite the real start time with a later one.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(WorkStart::TYPES))],
        ]);

        $existing = WorkStart::where('type', $data['type'])
            ->whereDate('date', now()->toDateString())
            ->first();

        $record = WorkStart::record($data['type'], $request->user()->id);

        return $this->success([
            'type' => $record->type,
            'label' => $record->type_label,
            'started_at' => $record->started_at_time,
            'started_by' => $record->user?->name,
            'is_late' => $record->is_late,
            'late_minutes' => $record->late_minutes,
            'deadline' => substr((string) $record->deadline, 0, 5),
            'warning' => $record->warning,
            'late_sequence' => $record->late_sequence,
            'penalty' => Money::convert($record->penalty_amount),
            'penalty_formatted' => Money::format($record->penalty_amount),
            'board' => WorkStart::todayBoard(),
        ], $existing
            ? 'این مورد قبلاً ثبت شده بود.'
            : ($record->is_late ? $record->warning : 'شروع کار ثبت شد.'),
            $existing ? 200 : 201);
    }

    /**
     * Late starts over a period — what payroll needs to apply a deduction.
     */
    public function lateReport(Request $request): JsonResponse
    {
        $from = Jalali::parseFlexible($request->query('from'));
        $until = Jalali::parseFlexible($request->query('until'));

        if (! $from || ! $until) {
            [$from, $until] = Jalali::currentMonthRange();
        }

        $records = WorkStart::whereBetween('date', [
            $from->toDateString(), $until->toDateString(),
        ])->with('user:id,name')->orderBy('date')->get();

        $late = $records->where('is_late', true);

        return $this->success([
            'from' => $from->toDateString(),
            'until' => $until->toDateString(),
            'period_label' => AppCalendar::monthLabel($from),
            'total_days_recorded' => $records->groupBy('date')->count(),
            'late_count' => $late->count(),
            'late_minutes_total' => (int) $late->sum('late_minutes'),
            'late_days' => $late->pluck('date')
                ->map(fn ($d) => $d?->toDateString())->unique()->count(),
            'penalty_total' => Money::convert($late->sum('penalty_amount')),
            'penalty_total_formatted' => Money::format($late->sum('penalty_amount')),
            'tariff' => LatePenalty::tariff(),
            'by_type' => collect(WorkStart::TYPES)->map(fn ($label, $type) => [
                'type' => $type,
                'label' => $label,
                'recorded' => $records->where('type', $type)->count(),
                'late' => $late->where('type', $type)->count(),
                'late_minutes' => (int) $late->where('type', $type)->sum('late_minutes'),
            ])->values(),
            // Grouped by person, since a deduction is applied to someone.
            'by_user' => $late->groupBy('user_id')->map(fn ($group) => [
                'user' => $group->first()->user?->name,
                'late_count' => $group->count(),
                'late_minutes' => (int) $group->sum('late_minutes'),
                'penalty' => Money::convert($group->sum('penalty_amount')),
                'penalty_formatted' => Money::format($group->sum('penalty_amount')),
            ])->values(),
            'records' => $late->map(fn (WorkStart $w) => [
                'date' => $w->date?->toDateString(),
                'date_display' => $w->date_display,
                'type' => $w->type,
                'label' => $w->type_label,
                'started_at' => $w->started_at_time,
                'deadline' => substr((string) $w->deadline, 0, 5),
                'late_minutes' => $w->late_minutes,
                'late_sequence' => $w->late_sequence,
                'penalty' => Money::convert($w->penalty_amount),
                'penalty_formatted' => Money::format($w->penalty_amount),
                'user' => $w->user?->name,
            ])->values(),
        ]);
    }
}
