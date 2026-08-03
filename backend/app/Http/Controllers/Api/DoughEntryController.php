<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DoughEntry;
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
            // Which yeast: fresh proves faster, so it is what winter calls
            // for; dry is the rest of the year.
            'yeast_type' => ['nullable', 'in:dry,wet'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $type = $data['yeast_type'] ?? DoughFormula::DRY;
        $formula = DoughFormula::fromBakery();
        $bags = (int) $data['bag_count'];

        $entry = ProductionRecorder::dough(
            $bags,
            $request->user()->id,
            $data['note'] ?? null,
            $type,
        );

        return $this->success([
            'entry' => $entry,
            'yeast_type' => $entry->yeast_type,
            'yeast_type_label' => $entry->yeast_type_label,
            // The expected yield, so the app can show it straight away.
            'expected' => [
                'flour_kg' => $formula->flourKg($bags),
                'water_kg' => $formula->waterKg($bags),
                'salt_kg' => $formula->saltKg($bags),
                'yeast_kg' => $formula->yeastKg($bags),
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
