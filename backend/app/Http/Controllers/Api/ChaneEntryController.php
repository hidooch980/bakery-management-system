<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\FlourStockMovement;
use App\Models\InventoryItem;
use App\Support\DoughFormula;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChaneEntryController extends Controller
{
    use ApiResponse;

    /**
     * Chane gir records the chane produced from a pending dough batch.
     * Marks the dough batch as processed and deducts the spray flour used.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dough_entry_id' => ['required', 'exists:dough_entries,id'],
            'chane_count' => ['required', 'integer', 'min:1', 'max:100000'],
            'nanino_chane_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'spray_flour_kg' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        // Chane weights come from the admin's dough formula, never from the
        // client, so the shop floor cannot enter a figure that contradicts it.
        $formula = DoughFormula::fromBakery();
        $normalCount = (int) $data['chane_count'];
        $naninoCount = (int) ($data['nanino_chane_count'] ?? 0);

        $normalWeight = $formula->weightForNormalChane($normalCount);
        $naninoWeight = $formula->weightForNaninoChane($naninoCount);

        if ($normalWeight === null) {
            return $this->error(
                'وزن هر چانه عادی در تنظیمات نانوایی تعریف نشده است. لطفاً با مدیر تماس بگیرید.',
                422
            );
        }

        $dough = DoughEntry::find($data['dough_entry_id']);

        if ($dough->status !== 'pending') {
            return $this->error('برای این خمیر قبلاً چانه ثبت شده است.', 409);
        }

        $entry = DB::transaction(function () use ($data, $dough, $request, $normalCount, $normalWeight, $naninoWeight) {
            $entry = ChaneEntry::create([
                'dough_entry_id' => $dough->id,
                'user_id' => $request->user()->id,
                'chane_count' => $normalCount,
                'normal_weight_kg' => $normalWeight,
                'nanino_weight_kg' => $naninoWeight ?? 0,
                'spray_flour_kg' => $data['spray_flour_kg'],
                'status' => 'pending',
            ]);

            $dough->update(['status' => 'processed']);

            if ($data['spray_flour_kg'] > 0) {
                FlourStockMovement::create([
                    'user_id' => $request->user()->id,
                    'type' => 'out',
                    'amount_kg' => $data['spray_flour_kg'],
                    'note' => "آرد پاششی چانه #{$entry->id}",
                ]);

                InventoryItem::ofKey(InventoryItem::FLOUR)->move(
                    'out', (float) $data['spray_flour_kg'], 'spray', $request->user()->id, $entry
                );
            }

            // Shaping turns dough into chane, so the dough stock drops.
            InventoryItem::ofKey(InventoryItem::DOUGH)->move(
                'out',
                round($normalWeight + ($naninoWeight ?? 0), 3),
                'production',
                $request->user()->id,
                $entry
            );

            return $entry;
        });

        return $this->success([
            'entry' => $entry,
            'total_weight_kg' => round($entry->normal_weight_kg + $entry->nanino_weight_kg, 2),
            'derived_from_formula' => true,
        ], 'ثبت چانه انجام شد.', 201);
    }

    /**
     * The chane gir's own submission history.
     */
    public function myHistory(Request $request): JsonResponse
    {
        $entries = ChaneEntry::where('user_id', $request->user()->id)
            ->with('doughEntry:id,bag_count')
            ->latest()
            ->paginate(20);

        return $this->success($entries);
    }

    /**
     * Chane batches not yet sold — the seller's work queue.
     */
    public function pending(): JsonResponse
    {
        $entries = ChaneEntry::pending()
            ->with(['user:id,name', 'doughEntry:id,bag_count'])
            ->latest()
            ->paginate(20);

        return $this->success($entries);
    }
}
