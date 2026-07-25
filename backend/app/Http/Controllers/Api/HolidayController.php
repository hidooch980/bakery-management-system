<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HolidayController extends Controller
{
    use ApiResponse;

    /** Readable by every signed-in user so the app can flag closed days. */
    public function index(Request $request): JsonResponse
    {
        $holidays = Holiday::query()
            ->when($request->boolean('this_month'), fn ($q) => $q->inJalaliMonth(now()))
            ->when($request->boolean('upcoming'), fn ($q) => $q->upcoming())
            ->orderBy('date')
            ->get()
            ->map(fn (Holiday $h) => $this->payload($h));

        return $this->success($holidays);
    }

    /** Whether the bakery is closed today. */
    public function today(): JsonResponse
    {
        $holiday = Holiday::whereDate('date', now()->toDateString())->first();

        return $this->success([
            'is_holiday' => $holiday !== null,
            'holiday' => $holiday ? $this->payload($holiday) : null,
            'date_display' => AppCalendar::date(now()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'string', 'max:20'],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(array_keys(Holiday::TYPES))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $date = Jalali::parseFlexible($data['date']);

        if ($date === null) {
            throw ValidationException::withMessages([
                'date' => ['تاریخ نامعتبر است. قالب درست: ۱۴۰۵/۰۵/۰۳'],
            ]);
        }

        if (Holiday::whereDate('date', $date->toDateString())->exists()) {
            return $this->error('برای این روز قبلاً تعطیلی ثبت شده است.', 409);
        }

        $holiday = Holiday::create([
            'date' => $date,
            'title' => $data['title'],
            'type' => $data['type'] ?? 'official',
            'note' => $data['note'] ?? null,
        ]);

        return $this->success($this->payload($holiday), 'تعطیلی ثبت شد.', 201);
    }

    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(array_keys(Holiday::TYPES))],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $holiday->update($data);

        return $this->success($this->payload($holiday->fresh()), 'تعطیلی به‌روزرسانی شد.');
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $holiday->delete();

        return $this->success(null, 'تعطیلی حذف شد.');
    }

    public function types(): JsonResponse
    {
        return $this->success(
            collect(Holiday::TYPES)->map(fn ($label, $value) => compact('value', 'label'))->values()
        );
    }

    private function payload(Holiday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'date' => $holiday->date?->toDateString(),
            'date_display' => $holiday->date_display,
            'title' => $holiday->title,
            'type' => $holiday->type,
            'type_label' => $holiday->type_label,
            'note' => $holiday->note,
            'is_past' => $holiday->date?->isPast() ?? false,
        ];
    }
}
