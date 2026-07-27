<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Support\DoughFormula;
use App\Support\ProductionRecorder;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoughEntryController extends Controller
{
    use ApiResponse;

    /**
     * Dough maker records how many flour bags were kneaded.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bag_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $formula = DoughFormula::fromBakery();
        $bags = (int) $data['bag_count'];

        $entry = ProductionRecorder::dough($bags, $request->user()->id, $data['note'] ?? null);

        return $this->success([
            'entry' => $entry,
            // The expected yield, so the app can show it straight away.
            'expected' => [
                'flour_kg' => $formula->flourKg($bags),
                'water_kg' => $formula->waterKg($bags),
                'salt_kg' => $formula->saltKg($bags),
                'dough_kg' => $formula->doughKg($bags),
                'normal_chane_count' => $formula->normalChaneCount($bags),
                'nanino_chane_count' => $formula->naninoChaneCount($bags),
            ],
        ], 'ثبت خمیر انجام شد.', 201);
    }

    /**
     * The dough maker's own submission history.
     */
    public function myHistory(Request $request): JsonResponse
    {
        $entries = DoughEntry::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return $this->success($entries);
    }

    /**
     * Dough batches not yet turned into chane — the chane gir's work queue.
     */
    public function pending(): JsonResponse
    {
        $entries = DoughEntry::pending()
            ->with('user:id,name')
            ->latest()
            ->paginate(20);

        return $this->success($entries);
    }
}
