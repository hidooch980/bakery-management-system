<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Support\DoughFormula;
use App\Support\Exclusively;
use App\Support\ProductionRecorder;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            'chane_count' => ['required_without:trays', 'integer', 'min:1', 'max:100000'],
            'nanino_chane_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'spray_flour_kg' => ['required', 'numeric', 'min:0', 'max:100000'],

            // Set only after the person has been shown that today's batch
            // is already recorded and has said this is a second one. The
            // same shape the dough sheet uses.
            'force' => ['nullable', 'boolean'],

            // Chane is counted out a tray at a time, so the app sends one
            // count per tray. The older single chane_count is still
            // accepted, so a copy of the app that has not updated keeps
            // working.
            'trays' => ['nullable', 'array', 'min:1', 'max:200'],
            'trays.*' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        // The batch total is the sum of the trays, worked out here rather
        // than trusted from the client, so the count can never disagree
        // with the trays it is supposed to be made of.
        $trays = $data['trays'] ?? null;
        $trayCount = $trays === null ? null : count($trays);

        // Chane weights come from the admin's dough formula, never from the
        // client, so the shop floor cannot enter a figure that contradicts it.
        //
        // Normal chane is what counts for sales, pricing and every report —
        // nanino is a display figure there. But dough is a physical
        // material: shaping it into nanino consumes it exactly as shaping
        // it into normal chane does, so the dough-stock deduction below is
        // the one place nanino's weight is not just a display number.
        $formula = DoughFormula::fromBakery();
        $normalCount = $trays === null
            ? (int) $data['chane_count']
            : array_sum($trays);
        $naninoCount = (int) ($data['nanino_chane_count'] ?? 0);

        $normalWeight = $formula->weightForNormalChane($normalCount);
        $naninoWeight = $formula->weightForNaninoChane($naninoCount);

        if ($normalWeight === null) {
            return $this->error(
                'وزن هر چانه عادی در تنظیمات نانوایی تعریف نشده است. لطفاً با مدیر تماس بگیرید.',
                422
            );
        }

        // One batch a day, at the owner's word on 1405/06/03. The dough
        // guard alone was not enough: a chane entry is one per *dough*,
        // and nothing stopped a second dough from being shaped later the
        // same day if one somehow existed.
        $shapedToday = ChaneEntry::whereDate('created_at', now()->toDateString())->first();

        if ($shapedToday && ! $request->boolean('force')) {
            return $this->error(
                sprintf(
                    'امروز %d چانه ثبت شده است. روزی یک بار.',
                    $shapedToday->chane_count,
                ),
                409,
            );
        }

        // The dough is locked for the whole of this, and both questions
        // below are asked of the locked copy. Asked of a copy read a
        // moment earlier, two chane makers on two phones would both see
        // `pending` and both record — spray flour out of the warehouse
        // twice, against a batch of dough that only exists once.
        $entry = Exclusively::claim(
            DoughEntry::findOrFail($data['dough_entry_id']),
            fn (DoughEntry $dough) => $dough->status === 'pending'
                ? null
                : 'برای این خمیر قبلاً چانه ثبت شده است.',
            function (DoughEntry $dough) use ($request, $data, $normalWeight, $naninoWeight, $normalCount, $trayCount, $trays) {
                // A batch yields a known weight of dough and no more.
                // Without this an over-long count quietly consumes the
                // next batch's dough — and inside the lock, because it is
                // that batch's remaining weight it is measuring.
                if ($problem = ProductionRecorder::problemWithChane(
                    $dough, (float) $normalWeight, (float) ($naninoWeight ?? 0)
                )) {
                    throw ValidationException::withMessages(['chane' => [$problem]]);
                }

                return ProductionRecorder::chane(
                    dough: $dough,
                    userId: $request->user()->id,
                    normalWeightKg: (float) $normalWeight,
                    naninoWeightKg: (float) ($naninoWeight ?? 0),
                    sprayFlourKg: (float) $data['spray_flour_kg'],
                    chaneCount: $normalCount,
                    trayCount: $trayCount,
                    trayCounts: $trays,
                );
            },
        );

        return $this->success([
            'entry' => $entry,
            // The weight that counts is the normal chane; nanino is reported
            // alongside it purely for comparison.
            'total_weight_kg' => round((float) $entry->normal_weight_kg, 2),
            'nanino_weight_kg' => round((float) $entry->nanino_weight_kg, 2),
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
