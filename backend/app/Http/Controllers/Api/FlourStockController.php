<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Support\AppCalendar;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The flour view of the warehouse.
 *
 * These endpoints once had a ledger of their own, separate from the
 * warehouse every other part of the system writes to. Production, sales and
 * consignment all moved the warehouse and left that second ledger behind, so
 * the two answered the same question differently — badly enough that the old
 * one had drifted to a negative balance, which no store of flour can be.
 *
 * There is now one ledger. These routes read and write it like everything
 * else, keeping their original response shape so existing readers are
 * undisturbed.
 */
class FlourStockController extends Controller
{
    use ApiResponse;

    private function flour(): InventoryItem
    {
        return InventoryItem::ofKey(InventoryItem::FLOUR);
    }

    /** Current flour balance, derived from the warehouse ledger. */
    public function balance(): JsonResponse
    {
        $item = $this->flour();

        $in = (float) $item->movements()->where('direction', 'in')->sum('quantity');
        $out = (float) $item->movements()->where('direction', 'out')->sum('quantity');

        $bagWeight = \App\Support\DoughFormula::fromBakery()->bagWeightKg;
        $inBags = fn (float $kg) => $bagWeight > 0 ? round($kg / $bagWeight, 2) : 0.0;

        return $this->success([
            'total_in_kg' => round($in, 2),
            'total_out_kg' => round($out, 2),
            'balance_kg' => round($in - $out, 2),
            'total_in_bags' => $inBags($in),
            'total_out_bags' => $inBags($out),
            'balance_bags' => $inBags($in - $out),
            'bag_weight_kg' => $bagWeight,
        ]);
    }

    public function index(): JsonResponse
    {
        $movements = $this->flour()->movements()
            ->with('user:id,name')
            ->latest()
            ->paginate(20)
            ->through(fn (InventoryMovement $m) => $this->payload($m));

        return $this->success($movements);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:in,out'],
            // Sacks are how flour arrives and leaves. A weight is still
            // taken for the odd amount that was actually weighed out.
            'bags' => ['required_without:amount_kg', 'nullable', 'numeric', 'min:0.01'],
            'amount_kg' => ['required_without:bags', 'nullable', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $bagWeight = \App\Support\DoughFormula::fromBakery()->bagWeightKg;

        if (isset($data['bags'])) {
            if ($bagWeight <= 0) {
                return $this->error('وزن کیسه آرد در تنظیمات ثبت نشده است.', 422);
            }

            $data['amount_kg'] = round((float) $data['bags'] * $bagWeight, 3);
        }

        // move() refuses an "out" larger than the balance, so a hand-entered
        // figure cannot push the store below zero the way the old ledger did.
        $movement = $this->flour()->move(
            $data['type'],
            (float) $data['amount_kg'],
            $data['type'] === 'in' ? 'purchase' : 'manual',
            $request->user()->id,
            null,
            $data['note'] ?? null,
        );

        return $this->success($this->payload($movement), 'تراکنش آرد ثبت شد.', 201);
    }

    /** The shape this endpoint has always returned. */
    private function payload(InventoryMovement $movement): array
    {
        $bagWeight = \App\Support\DoughFormula::fromBakery()->bagWeightKg;

        return [
            'id' => $movement->id,
            'type' => $movement->direction,
            'amount_kg' => (float) $movement->quantity,
            'bags' => $bagWeight > 0
                ? round((float) $movement->quantity / $bagWeight, 2)
                : null,
            'reason' => $movement->reason,
            'reason_label' => $movement->reason_label,
            'note' => $movement->note,
            'user' => $movement->user?->only(['id', 'name']),
            'created_at' => $movement->created_at?->toIso8601String(),
            'created_at_display' => AppCalendar::date($movement->created_at),
        ];
    }
}
