<?php

namespace App\Http\Middleware;

use App\Support\CurrentBakery;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which of an owner's shops this request is about.
 *
 * Most people reach one shop and never send anything: the header is
 * absent, nothing here fires, and `CurrentBakery` answers from
 * `users.bakery_id` exactly as it always has. That is every member of
 * staff, and the reason this can ship to a working shop.
 *
 * For somebody who holds several, the phone names the one on screen.
 * Kept in the request rather than on the user row on purpose — a person
 * signed in on two devices would otherwise change what the other one is
 * looking at, and the second device would not know why its figures moved.
 *
 * The id is checked, never trusted. It arrives from a client and naming
 * a shop is a request to look at it, not permission to: without
 * `canReachBakery`, a header would be enough to read another shop's
 * money. An id the person may not reach is ignored rather than refused —
 * their own shop is always a correct answer, and a 403 in the middle of
 * a sales screen helps nobody standing at a counter.
 */
class PicksTheBakery
{
    public const HEADER = 'X-Bakery-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $asked = $request->header(self::HEADER);

        if ($asked !== null && $request->user()?->canReachBakery((int) $asked)) {
            CurrentBakery::actAs((int) $asked);
        }

        return $next($request);
    }
}
