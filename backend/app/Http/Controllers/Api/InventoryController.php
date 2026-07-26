<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Support\AppCalendar;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    use ApiResponse;

    /** Current balance of every stocked item. */
    public function index(): JsonResponse
    {
        // Make sure the three standard items always exist for a fresh install.
        foreach (array_keys(InventoryItem::DEFAULTS) as $key) {
            InventoryItem::ofKey($key);
        }

        $items = InventoryItem::all()->map(fn (InventoryItem $item) => [
            'id' => $item->id,
            'key' => $item->key,
            'name' => $item->name,
            'unit' => $item->unit,
            'balance' => $item->balance,
            // Null only when this item has no configured bag/sack size.
            'balance_bags' => $item->balance_bags,
            'low_threshold' => $item->low_threshold ? (float) $item->low_threshold : null,
            'is_low' => $item->is_low,
        ]);

        return $this->success($items);
    }

    public function movements(Request $request): JsonResponse
    {
        $movements = InventoryMovement::with(['item:id,key,name,unit', 'user:id,name'])
            ->when($request->query('item'), fn ($q, $key) => $q->whereHas(
                'item', fn ($i) => $i->where('key', $key)
            ))
            ->when($request->query('direction'), fn ($q, $d) => $q->where('direction', $d))
            ->latest()
            ->paginate(30)
            ->through(fn (InventoryMovement $m) => [
                'id' => $m->id,
                'item' => $m->item?->only(['key', 'name', 'unit']),
                'direction' => $m->direction,
                'quantity' => (float) $m->quantity,
                'reason' => $m->reason,
                'reason_label' => $m->reason_label,
                'note' => $m->note,
                'user' => $m->user?->only(['id', 'name']),
                'created_at' => $m->created_at?->toDateTimeString(),
                'created_at_display' => AppCalendar::dateTime($m->created_at),
            ]);

        return $this->success($movements);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item' => ['required', Rule::in(array_keys(InventoryItem::DEFAULTS))],
            'direction' => ['required', 'in:in,out'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'reason' => ['nullable', Rule::in(array_keys(InventoryMovement::REASONS))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $item = InventoryItem::ofKey($data['item']);

        $movement = $item->move(
            $data['direction'],
            (float) $data['quantity'],
            $data['reason'] ?? 'manual',
            $request->user()->id,
            null,
            $data['note'] ?? null,
        );

        return $this->success([
            'movement_id' => $movement->id,
            'balance' => $item->fresh()->balance,
        ], 'تراکنش انبار ثبت شد.', 201);
    }

    public function updateThreshold(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'low_threshold' => ['nullable', 'numeric', 'min:0'],
        ]);

        $item = InventoryItem::ofKey($key);
        $item->update(['low_threshold' => $data['low_threshold']]);

        return $this->success(['low_threshold' => $item->low_threshold]);
    }
}
