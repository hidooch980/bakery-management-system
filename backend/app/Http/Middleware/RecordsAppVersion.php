<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes which build of the app a session is running, when it changes.
 *
 * Recorded at every request rather than only at sign-in, because nobody
 * signs in again after updating — the token outlives the install. A field
 * that says 5.1.0 while the phone runs 5.2.0 is worse than an empty one:
 * it is wrong with the confidence of a fact, and it would be read as one
 * on the day somebody is trying to work out why a screen is blank.
 *
 * The write happens only when the value differs, so the ordinary case is
 * a string comparison against a column already loaded with the token.
 *
 * Nothing here can refuse a request. The version is a convenience for
 * whoever is diagnosing a problem; a phone that sends a malformed header,
 * or none at all, still sells bread.
 */
class RecordsAppVersion
{
    /** Long enough for a semantic version and the build metadata. */
    private const MAX = 20;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // `currentAccessToken` answers with a TransientToken for a
        // session-guard request — the panel — and that is a plain object,
        // not a model: it has no row, no column, and no `exists` either.
        // Asking it for one is a fatal error on every panel request, which
        // is how this arrived: `! $token->exists` looked like the careful
        // version of the check and was the one that broke the desk.
        if (! $token instanceof Model) {
            return $next($request);
        }

        $sent = $this->clean($request->header('X-App-Version'));

        if ($sent !== null && $sent !== $token->app_version) {
            $token->forceFill(['app_version' => $sent])->save();
        }

        return $next($request);
    }

    /**
     * Keeps what looks like a version and discards the rest.
     *
     * This string is written into a column that is read back and shown to
     * the owner, and it arrives from the network. Anything that is not
     * digits, dots, dashes and letters is not a version number.
     */
    private function clean(?string $raw): ?string
    {
        $value = trim($raw ?? '');

        if ($value === '' || ! preg_match('/^[0-9A-Za-z.+-]{1,'.self::MAX.'}$/', $value)) {
            return null;
        }

        return $value;
    }
}
