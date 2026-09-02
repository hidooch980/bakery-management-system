<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SystemIssue;
use App\Support\TodayAnswer;
use Illuminate\Http\JsonResponse;

/**
 * One answer for the phone: is the shop sound, and what is the owner's.
 *
 * The same words the panel opens with, composed in `TodayAnswer` so the
 * two cannot drift. The owner is more often beside the oven than at the
 * desk, and an answer he can only get by sitting down is an answer he will
 * go on getting by asking a person instead — which is how a 400 kg hole in
 * the ledger survived four days of green screens.
 *
 * Read-only, and behind the same permission as the dashboard it stands in
 * front of.
 */
class TodayController extends Controller
{
    public function show(): JsonResponse
    {
        $answer = TodayAnswer::now();
        $sentence = $answer->sentence();

        return response()->json([
            'success' => true,
            'data' => [
                'tone' => $sentence['tone'],
                'system' => $sentence['system'],
                'yours' => $sentence['yours'],

                // The cycle count travels with the answer rather than
                // being written into the app, so adding a cycle does not
                // need a release to stop the phone claiming the old number.
                'cycles' => $answer->cycleCount(),
                'sound' => $answer->health->isSound(),

                // Only when something is actually wrong. An empty list on
                // a healthy shop is what the phone should draw nothing for.
                'failures' => $answer->health->failures(),
                'warnings' => $answer->health->warnings(),

                'needs' => $answer->needs->map(fn (SystemIssue $issue) => [
                    'key' => $issue->key,
                    'severity' => $issue->severity,
                    'title' => $issue->title,
                    'detail' => $issue->detail,
                    // What to do about it, for the phone's detail sheet.
                    // The panel puts this behind a click; a phone has
                    // nowhere else to put it.
                    'suggestion' => $issue->suggestion,
                ])->values(),

                'figures' => $answer->figures(),
            ],
        ]);
    }
}
