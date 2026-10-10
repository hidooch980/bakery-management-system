<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Support\AppCalendar;
use App\Support\DoughFormula;
use App\Support\Jalali;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «گردش روزانه آرد برای فروشنده»: یک روز انبار آرد، فقط به کیسه.
 *
 * موجودی اول روز، ورودی‌ها (خرید، دریافت از همکار، …)، خروجی‌ها (خمیر،
 * پاششی، فروش آرد، تحویل به همکار، …)، موجودی آخر روز، و ریز حرکت‌های
 * همان روز با ساعت. فقط خواندنی است و از همان دفتر انبار خوانده می‌شود
 * که پنل می‌خواند، پس عددش با پنل یکی است. محدود به نانوایی خودِ کاربر
 * (BelongsToBakery).
 */
class FlourDayController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'string', 'max:20']]);

        $day = (Jalali::parseFlexible($request->query('date')) ?? now())->copy()->startOfDay();
        $end = $day->copy()->endOfDay();

        $item = InventoryItem::ofKey(InventoryItem::FLOUR);
        $bagWeight = DoughFormula::fromBakery()->bagWeightKg;
        $bags = fn (float $kg) => $bagWeight > 0 ? round($kg / $bagWeight, 2) : 0.0;

        $signed = "SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END)";

        $openingKg = (float) InventoryMovement::query()
            ->where('inventory_item_id', $item->id)
            ->where('created_at', '<', $day)
            ->selectRaw("COALESCE({$signed}, 0) AS kg")
            ->value('kg');

        $movements = InventoryMovement::query()
            ->with('user:id,name')
            ->where('inventory_item_id', $item->id)
            ->whereBetween('created_at', [$day, $end])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $inKg = (float) $movements->where('direction', 'in')->sum('quantity');
        $outKg = (float) $movements->where('direction', 'out')->sum('quantity');

        $breakdown = fn (string $direction) => $movements
            ->where('direction', $direction)
            ->groupBy('reason')
            ->map(fn ($group, $reason) => [
                'reason' => $reason,
                'label' => InventoryMovement::REASONS[$reason] ?? $reason,
                'bags' => $bags((float) $group->sum('quantity')),
                'count' => $group->count(),
            ])
            ->sortByDesc('bags')
            ->values();

        return $this->success([
            'date' => $day->toDateString(),
            'date_display' => AppCalendar::date($day),
            'is_today' => $day->isSameDay(now()),
            'previous_date' => $day->copy()->subDay()->toDateString(),
            // فردای امروز هنوز گردشی ندارد.
            'next_date' => $day->copy()->addDay()->lte(now()) ? $day->copy()->addDay()->toDateString() : null,
            'bag_weight_kg' => $bagWeight,
            'opening_bags' => $bags($openingKg),
            'in_bags' => $bags($inKg),
            'out_bags' => $bags($outKg),
            'closing_bags' => $bags($openingKg + $inKg - $outKg),
            'in' => $breakdown('in'),
            'out' => $breakdown('out'),
            'movements' => $movements->map(fn (InventoryMovement $m) => [
                'id' => $m->id,
                'time' => $m->created_at?->format('H:i'),
                'direction' => $m->direction,
                'reason' => $m->reason,
                'label' => $m->reason_label,
                'bags' => $bags((float) $m->quantity),
                'note' => $m->note,
                'user' => $m->user?->name,
            ])->values(),
        ]);
    }
}
