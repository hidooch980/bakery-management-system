<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    use ApiResponse;

    /**
     * Record today's check-in for the authenticated staff member.
     * The check-in time becomes visible to the admin through reports.
     */
    public function checkIn(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = now()->toDateString();

        if (Attendance::where('user_id', $user->id)->where('date', $today)->exists()) {
            return $this->error('حضور شما برای امروز قبلاً ثبت شده است.', 409);
        }

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'date' => $today,
            'checked_in_at' => now(),
        ]);

        return $this->success([
            'id' => $attendance->id,
            'date' => $attendance->date->toDateString(),
            'checked_in_at' => $attendance->checked_in_at->toDateTimeString(),
        ], 'تیک حضور ثبت شد.', 201);
    }

    /**
     * Today's attendance status for the authenticated user.
     */
    public function today(Request $request): JsonResponse
    {
        $attendance = Attendance::where('user_id', $request->user()->id)
            ->where('date', now()->toDateString())
            ->first();

        return $this->success([
            'checked_in' => (bool) $attendance,
            'checked_in_at' => $attendance?->checked_in_at?->toDateTimeString(),
        ]);
    }

    /**
     * The authenticated user's own attendance history.
     */
    public function myHistory(Request $request): JsonResponse
    {
        $records = Attendance::where('user_id', $request->user()->id)
            ->latest('date')
            ->paginate(30);

        return $this->success($records);
    }
}
