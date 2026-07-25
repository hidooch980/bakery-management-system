<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\FlourStockMovement;
use App\Models\Sale;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-facing reporting. All endpoints accept optional `from` / `to`
 * date filters and default to the current day.
 */
class ReportController extends Controller
{
    use ApiResponse;

    public function dashboard(): JsonResponse
    {
        $today = now()->toDateString();

        return $this->success([
            'today' => [
                'dough_bags' => (int) DoughEntry::whereDate('created_at', $today)->sum('bag_count'),
                'chane_count' => (int) ChaneEntry::whereDate('created_at', $today)->sum('chane_count'),
                'sales_count' => Sale::whereDate('created_at', $today)->count(),
                'sales_amount' => round((float) Sale::whereDate('created_at', $today)->sum('amount'), 2),
                'attendance_count' => Attendance::where('date', $today)->count(),
            ],
            'queues' => [
                'pending_dough' => DoughEntry::pending()->count(),
                'pending_chane' => ChaneEntry::pending()->count(),
            ],
            'staff' => [
                'total' => User::count(),
                'active' => User::where('is_active', true)->count(),
            ],
            'flour_balance_kg' => $this->flourBalance(),
        ]);
    }

    public function production(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $dough = DoughEntry::whereBetween('created_at', [$from, $to]);
        $chane = ChaneEntry::whereBetween('created_at', [$from, $to]);

        return $this->success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_dough_bags' => (int) $dough->sum('bag_count'),
            'total_dough_entries' => $dough->count(),
            'total_chane_count' => (int) $chane->sum('chane_count'),
            'total_normal_weight_kg' => round((float) $chane->sum('normal_weight_kg'), 2),
            'total_nanino_weight_kg' => round((float) $chane->sum('nanino_weight_kg'), 2),
            'total_spray_flour_kg' => round((float) $chane->sum('spray_flour_kg'), 2),
        ]);
    }

    public function sales(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $sales = Sale::whereBetween('created_at', [$from, $to])->get();

        return $this->success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'count' => $sales->count(),
            'total_amount' => round((float) $sales->sum('amount'), 2),
            'by_payment_type' => $sales->groupBy('payment_type')->map(fn ($group) => [
                'count' => $group->count(),
                'amount' => round((float) $group->sum('amount'), 2),
            ]),
            'by_seller' => $sales->groupBy('user_id')->map(fn ($group) => [
                'seller' => User::find($group->first()->user_id)?->name,
                'count' => $group->count(),
                'amount' => round((float) $group->sum('amount'), 2),
            ])->values(),
        ]);
    }

    public function flourConsumption(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return $this->success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'flour_in_kg' => round((float) FlourStockMovement::where('type', 'in')
                ->whereBetween('created_at', [$from, $to])->sum('amount_kg'), 2),
            'flour_out_kg' => round((float) FlourStockMovement::where('type', 'out')
                ->whereBetween('created_at', [$from, $to])->sum('amount_kg'), 2),
            'spray_flour_kg' => round((float) ChaneEntry::whereBetween('created_at', [$from, $to])
                ->sum('spray_flour_kg'), 2),
            'current_balance_kg' => $this->flourBalance(),
        ]);
    }

    /**
     * Production efficiency: chane produced and dough weight per flour bag.
     */
    public function efficiency(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $bags = (int) DoughEntry::whereBetween('created_at', [$from, $to])->sum('bag_count');
        $chane = ChaneEntry::whereBetween('created_at', [$from, $to]);
        $chaneCount = (int) $chane->sum('chane_count');
        $totalWeight = (float) $chane->sum('normal_weight_kg') + (float) $chane->sum('nanino_weight_kg');

        return $this->success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_bags' => $bags,
            'total_chane' => $chaneCount,
            'total_weight_kg' => round($totalWeight, 2),
            'chane_per_bag' => $bags > 0 ? round($chaneCount / $bags, 2) : 0,
            'weight_per_bag_kg' => $bags > 0 ? round($totalWeight / $bags, 2) : 0,
        ]);
    }

    /**
     * Staff attendance report — this is where the admin sees check-in times.
     */
    public function attendance(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $records = Attendance::with('user:id,name')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->orderByDesc('date')
            ->orderByDesc('checked_in_at')
            ->paginate(50);

        return $this->success($records);
    }

    private function range(Request $request): array
    {
        $from = $request->query('from')
            ? now()->parse($request->query('from'))->startOfDay()
            : now()->startOfDay();

        $to = $request->query('to')
            ? now()->parse($request->query('to'))->endOfDay()
            : now()->endOfDay();

        return [$from, $to];
    }

    private function flourBalance(): float
    {
        $in = (float) FlourStockMovement::where('type', 'in')->sum('amount_kg');
        $out = (float) FlourStockMovement::where('type', 'out')->sum('amount_kg');

        return round($in - $out, 2);
    }
}
