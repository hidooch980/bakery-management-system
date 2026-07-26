<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Support\AppCalendar;
use App\Support\DoughFormula;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The read-only production board. The shater sees only how many chane are
 * waiting for the oven; the seller and chane gir also use it to compare
 * nanino output against normal output.
 */
class ChaneBoardController extends Controller
{
    use ApiResponse;

    public function show(): JsonResponse
    {
        $today = now()->toDateString();

        $pending = ChaneEntry::pending();
        $todayEntries = ChaneEntry::whereDate('created_at', $today)->get();

        $normalToday = (int) $todayEntries->sum('chane_count');
        $normalWeight = (float) $todayEntries->sum('normal_weight_kg');
        $naninoWeight = (float) $todayEntries->sum('nanino_weight_kg');

        $formula = DoughFormula::fromBakery();

        // Nanino count is derived from its weight, since the entry stores the
        // weight rather than a separate count.
        $naninoToday = $formula->naninoCountForWeight($naninoWeight);

        $total = $normalToday + $naninoToday;

        // What-if: how many nanino loaves today's normal-shaped dough would
        // have produced, had it been shaped as nanino instead. Not a count
        // of anything actually baked — a comparison figure only.
        $naninoEquivalent = $formula->naninoEquivalentForNormalCount($normalToday);

        return $this->success([
            'date_display' => AppCalendar::date(now()),
            'waiting' => [
                'chane_count' => (int) $pending->sum('chane_count'),
                'batches' => $pending->count(),
            ],
            'today' => [
                'normal_count' => $normalToday,
                'nanino_count' => $naninoToday,
                'total_count' => $total,
                'normal_weight_kg' => round($normalWeight, 2),
                'nanino_weight_kg' => round($naninoWeight, 2),
                'total_weight_kg' => round($normalWeight + $naninoWeight, 2),
                // Share of output from each system, for the comparison bar.
                'normal_share_percent' => $total > 0 ? round($normalToday / $total * 100, 1) : 0,
                'nanino_share_percent' => $total > 0 ? round($naninoToday / $total * 100, 1) : 0,
                'normal_as_nanino_equivalent' => $naninoEquivalent,
                'normal_as_nanino_announcement' => $naninoEquivalent === null
                    ? null
                    : "چانه‌های عادی امروز ({$normalToday} عدد) معادل {$naninoEquivalent} نان نانینو است.",
            ],
            'queues' => [
                'pending_dough_batches' => DoughEntry::pending()->count(),
                'pending_dough_bags' => (int) DoughEntry::pending()->sum('bag_count'),
            ],
        ]);
    }
}
