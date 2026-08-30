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
     * How long an identical batch counts as a double tap.
     *
     * On 24 Mordad the same thirteen bags went in three times in thirty-five
     * minutes -- the seller pressed again when the first did not look like
     * it had landed -- and each one took flour, salt and yeast out of the
     * store. Two batches of the same size within a quarter of an hour is
     * not how this shop bakes; the oven is not free again that fast.
     */
    private const DOUBLE_TAP_MINUTES = 15;

    /**
     * Dough maker records how many flour bags were kneaded.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bag_count' => ['required', 'integer', 'min:1', 'max:1000'],
            // Which yeast: fresh proves faster, so it is what winter calls
            // for; dry is the rest of the year.
            // Still accepts «wet» from a phone on an older build, and
            // ignores it. The tub is gone; refusing the field would stop
            // that phone recording a batch at all.
            'yeast_type' => ['nullable', 'in:dry,wet'],
            'note' => ['nullable', 'string', 'max:500'],
            // Set only after the person has been shown the batch they
            // already recorded and said it is a second one.
            'force' => ['nullable', 'boolean'],
        ]);

        // One batch a day, at the owner's word on 1405/06/03. The shop has
        // recorded 28 dough entries across 28 days and never two on one,
        // so this makes a rule of what already happens — and what it
        // actually catches is the second *entry*, not the second batch.
        //
        // Not per person: the shop kneads once, whoever is holding the
        // phone, and two people recording the same morning is exactly the
        // mistake this stops. A guard scoped to the user would let it by.
        //
        // Behind `force`, like the double-tap guard beside it, and that is
        // deliberate. On 24 Mordad somebody pressed «ثبت خمیر» three times
        // in thirty-five minutes for one thirteen-bag batch and spent
        // 1,040 kg of flour that never left the sack; the answer then was
        // to make a genuine second batch possible but deliberate, not
        // impossible. Refusing outright would mean a real second batch —
        // a big order, a holiday — could not be recorded at all, and an
        // unrecordable batch is one that goes unrecorded.
        $today = DoughEntry::whereDate('created_at', now()->toDateString())->first();

        if ($today && ! $request->boolean('force')) {
            return $this->error(
                sprintf(
                    'امروز %d کیسه خمیر ثبت شده است. روزی یک بار.',
                    $today->bag_count,
                ),
                409,
            );
        }

        if (! $request->boolean('force')) {
            $justRecorded = DoughEntry::where('user_id', $request->user()->id)
                ->where('bag_count', (int) $data['bag_count'])
                ->where('created_at', '>=', now()->subMinutes(self::DOUBLE_TAP_MINUTES))
                ->latest('created_at')
                ->first();

            if ($justRecorded) {
                return $this->error(
                    sprintf(
                        'همین %s پیش %d کیسه ثبت کرده‌اید. اگر این دستهٔ تازه‌ای است دوباره تأیید کنید.',
                        $justRecorded->created_at->diffForHumans(null, true),
                        $justRecorded->bag_count,
                    ),
                    409,
                );
            }
        }

        $formula = DoughFormula::fromBakery();
        $bags = (int) $data['bag_count'];

        // A `yeast_type` still arriving from an older build is accepted and
        // ignored rather than refused: the fresh tub is gone, and a phone
        // that has not been updated should not stop being able to record a
        // batch over it.
        $entry = ProductionRecorder::dough(
            $bags,
            $request->user()->id,
            $data['note'] ?? null,
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
