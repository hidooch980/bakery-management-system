<?php

namespace Tests\Feature;

use App\Http\Middleware\IdempotentWrites;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The guard is on the group, and a route is added by writing a line.
 *
 * `IdempotentWrites` is not listed route by route — it sits on the
 * authenticated group in `routes/api.php`, and every write inside that
 * group is covered by it without saying so. That is the right shape, and
 * it is also the fragile one: a route written outside the group, or a new
 * group added beside it, is unprotected and looks exactly like the others.
 *
 * Nothing would report it. The phone would send its `Idempotency-Key`,
 * the server would ignore it, and a payment whose answer was lost on the
 * way back would be written a second time when the owner pressed again —
 * the same failure this middleware exists to prevent, on the one route
 * that quietly opted out of it.
 *
 * So the rule is pinned instead of assumed: every write in the API is
 * behind the guard, except the three that have no signed-in user to
 * attach a name to — and the middleware is a no-op without one anyway.
 */
class EveryWriteCanSurviveBeingSentTwiceTest extends TestCase
{
    private const GUARDED_VERBS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Writes reached before sign-in, with the reason each one has to be.
     *
     * These run before sanctum has resolved anybody, so there is no user
     * to hang a remembered name on. None of them moves money or stock:
     * signing in twice signs you in, and a second reset mail is a
     * nuisance the controller rate-limits, not a figure in the books.
     */
    private const UNAUTHENTICATED_WRITES = [
        'POST api/v1/login' => 'no user yet — that is what it is for',
        'POST api/v1/forgot-password' => 'rate-limited per phone number instead',
        'POST api/v1/reset-password' => 'rate-limited, and carries a one-use token',
    ];

    /**
     * The route carries the guard, whether by class or by its alias.
     *
     * `gatherMiddleware()` hands back what was written in `routes/api.php`
     * — the string `idempotent` — not the class it stands for. Matching
     * only the class name passes every route that uses the alias, which
     * is all of them, so the aliases are resolved first.
     */
    private function isGuarded(\Illuminate\Routing\Route $route): bool
    {
        $aliases = app('router')->getMiddleware();

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            // `permission:manage-purchases` and the like carry arguments.
            $name = strtok($middleware, ':');
            $resolved = $aliases[$name] ?? $name;

            if ($resolved === IdempotentWrites::class) {
                return true;
            }
        }

        return false;
    }

    public function test_every_api_write_is_behind_the_duplicate_guard(): void
    {
        $unprotected = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $verbs = array_intersect($route->methods(), self::GUARDED_VERBS);

            if ($verbs === []) {
                continue;
            }

            if ($this->isGuarded($route)) {
                continue;
            }

            // HEAD rides along with GET and is never the interesting verb.
            $unprotected[] = reset($verbs).' '.$route->uri();
        }

        sort($unprotected);
        $allowed = array_keys(self::UNAUTHENTICATED_WRITES);
        sort($allowed);

        $this->assertSame(
            $allowed,
            $unprotected,
            'یک مسیرِ نوشتن بیرون از گروهِ محافظت‌شده افتاده است. اگر عمدی است،'
            .' با دلیلش به UNAUTHENTICATED_WRITES اضافه شود؛ وگرنه داخل گروهِ'
            .' auth:sanctum در routes/api.php برود.',
        );
    }
}
