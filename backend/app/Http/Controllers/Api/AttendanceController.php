<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\User;
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
     * The floor and whether each of them is in yet, for whoever is ticking
     * people in on their behalf.
     */
    public function roster(Request $request): JsonResponse
    {
        $today = now()->toDateString();

        $staff = User::query()
            ->where('is_active', true)
            ->whereKeyNot($request->user()->id)
            ->orderBy('name')
            ->get();

        $marked = Attendance::whereIn('user_id', $staff->pluck('id'))
            ->where('date', $today)
            ->get()
            ->keyBy('user_id');

        return $this->success([
            'staff' => $staff->map(function (User $person) use ($marked) {
                $attendance = $marked->get($person->id);

                return [
                    'id' => $person->id,
                    'name' => $person->name,
                    'role' => $person->getRoleNames()->first(),
                    'checked_in' => $attendance !== null,
                    'checked_in_at' => $attendance?->checked_in_at?->format('H:i'),
                    // So the person ticking can see they already did it,
                    // rather than wondering why the button went quiet.
                    'recorded_by_another' => (bool) $attendance?->was_recorded_by_another,
                ];
            })->values(),
        ]);
    }

    /**
     * Records another staff member's arrival.
     *
     * The floor works with flour on their hands and phones in a locker, so
     * the person who is already holding one enters it for them. Who entered
     * it is kept, because a tick someone else made is a different fact from
     * one you made yourself.
     */
    public function checkInFor(Request $request, User $user): JsonResponse
    {
        if (! $user->is_active) {
            return $this->error('این کارمند فعال نیست.', 422);
        }

        $today = now()->toDateString();

        if (Attendance::where('user_id', $user->id)->where('date', $today)->exists()) {
            return $this->error('حضور این کارمند برای امروز قبلاً ثبت شده است.', 409);
        }

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'date' => $today,
            'checked_in_at' => now(),
            'recorded_by' => $request->user()->id,
        ]);

        return $this->success([
            'id' => $attendance->id,
            'user_id' => $user->id,
            'name' => $user->name,
            'checked_in_at' => $attendance->checked_in_at->format('H:i'),
        ], 'حضور '.$user->name.' ثبت شد.', 201);
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
