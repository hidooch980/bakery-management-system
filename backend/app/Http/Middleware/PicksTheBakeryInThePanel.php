<?php

namespace App\Http\Middleware;

use App\Support\CurrentBakery;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which of an owner's shops the panel is showing.
 *
 * The phone has had this since owners could hold more than one shop:
 * [PicksTheBakery] reads a header the app sends. The panel had nothing.
 * An owner with two shops could open a second one from «نانوایی جدید» and
 * then had no way to look at it — every screen kept answering for
 * `users.bakery_id`, which is the shop they started in.
 *
 * The browser has no header to send, so the choice is kept in the session:
 * it survives the next page, which is the whole point, and it is per
 * sign-in, so the same owner on the counter machine and on their phone are
 * not moving each other's screens.
 *
 * Checked, never trusted — a session value is still user-facing state, and
 * [canReachBakery] is what says whether this person may look at that shop.
 * An id they may not reach is dropped rather than refused: their own shop
 * is always a correct answer.
 */
class PicksTheBakeryInThePanel
{
    public const KEY = 'panel.bakery_id';

    public function handle(Request $request, Closure $next): Response
    {
        $asked = $request->session()->get(self::KEY);

        if ($asked === null) {
            return $next($request);
        }

        if ($request->user()?->canReachBakery((int) $asked)) {
            CurrentBakery::actAs((int) $asked);
        } else {
            // Left in place it would be re-checked, and re-dropped, on
            // every page for the rest of the sign-in.
            $request->session()->forget(self::KEY);
        }

        return $next($request);
    }
}
