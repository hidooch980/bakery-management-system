<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account that has been switched off stops working, on every request.
 *
 * `is_active` used to be read in exactly one place — signing in — and a
 * token outlives the decision that issued it. So a phone whose account had
 * been deactivated carried on recording sales and reading wages until the
 * token expired on its own.
 *
 * The one path that did clean up was the panel's toggle action, which
 * deleted the tokens itself. Everything else missed: the edit form setting
 * the same column, a console command, a correction made in the database.
 * That is why this sits at the door instead of at each of those writes —
 * the next way into that column has not been written yet.
 *
 * The tokens go, rather than each request being turned away one at a time.
 * A key that is refused is still a key in somebody's pocket; if the
 * account is off, the session is over.
 */
class EndsInactiveSessions
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Not authenticated, or a model without the column: neither is
        // this middleware's business, and guessing «inactive» for either
        // would lock people out of routes that never had an account.
        if ($user === null || $user->is_active !== false) {
            return $next($request);
        }

        $user->tokens()->delete();

        return response()->json([
            'success' => false,
            'message' => 'حساب کاربری شما غیرفعال است.',
        ], 403);
    }
}
