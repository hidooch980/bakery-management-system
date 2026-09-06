<?php

namespace Tests\Feature;

use App\Models\SalaryPaymentRequest;
use App\Models\StaffAdvanceRequest;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Who can reach what, as a whole picture rather than one route at a time.
 *
 * A route is added by writing a line inside whichever group the line above
 * it happens to be in, and the group is thirty lines up. That is how the
 * staff-yield report was published behind `view-attendance-reports` — a
 * permission a *seller* holds — so what somebody's work came to was
 * readable by their colleagues. It had a permission. It had the wrong one,
 * and nothing anywhere was in a position to notice.
 *
 * So the matrix is pinned. Every API route is listed with the roles that
 * can reach it, and the list lives in `tests/route-access.txt` where it
 * can be read by a person. Moving a route between groups, adding one, or
 * granting a role a new permission all change that file, and the diff is
 * the review: «فروشنده» appearing on a line about wages is a sentence
 * anybody can judge, where `permission:view-attendance-reports` is not.
 *
 * Regenerate deliberately, never blindly:
 *
 *     UPDATE_ROUTE_ACCESS=1 php artisan test --filter=EveryRouteIsBehindTheRightDoor
 */
class EveryRouteIsBehindTheRightDoorTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT = __DIR__.'/../route-access.txt';

    /**
     * Routes open to anyone at all, with the reason each one has to be.
     *
     * Anything reaching this list is a decision; anything not on it and
     * unauthenticated is a mistake.
     */
    private const PUBLIC_ROUTES = [
        'POST api/v1/login' => 'the way in',
        'POST api/v1/forgot-password' => 'for somebody who cannot sign in',
        'POST api/v1/reset-password' => 'the other half of that',
        'GET api/v1/health' => 'read by the phone to tell «no signal» from «server down»',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @return array<string, string> route line => who can reach it */
    private function matrix(): array
    {
        $roles = Role::with('permissions')->get()
            ->mapWithKeys(fn (Role $r) => [$r->name => $r->permissions->pluck('name')->all()]);

        $rows = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1')) {
                continue;
            }

            $middleware = implode(' ', $route->gatherMiddleware());
            $needsAuth = str_contains($middleware, 'sanctum');

            preg_match('/(?:PermissionMiddleware|permission):([\w|-]+)/', $middleware, $match);
            $permission = $match[1] ?? null;

            $who = match (true) {
                ! $needsAuth => '(public)',
                $permission === null => '(any signed-in)',
                default => implode(', ', $roles
                    ->filter(fn (array $held) => array_intersect($held, explode('|', $permission)) !== [])
                    ->keys()
                    ->sort()
                    ->values()
                    ->all()) ?: '(nobody)',
            };

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $rows["{$method} {$uri}"] = $who;
            }
        }

        ksort($rows);

        return $rows;
    }

    private function render(array $matrix): string
    {
        $lines = [];

        foreach ($matrix as $route => $who) {
            $lines[] = str_pad($route, 60).$who;
        }

        return implode("\n", $lines)."\n";
    }

    public function test_who_can_reach_what_has_not_changed_unnoticed(): void
    {
        $rendered = $this->render($this->matrix());

        if (getenv('UPDATE_ROUTE_ACCESS')) {
            file_put_contents(self::SNAPSHOT, $rendered);
            $this->markTestSkipped('route-access.txt rewritten — read the diff before committing it.');
        }

        $this->assertFileExists(self::SNAPSHOT);

        $this->assertSame(
            file_get_contents(self::SNAPSHOT),
            $rendered,
            'Who can reach what has changed. Read the diff: a role appearing '
            .'on a line it has no business on is the bug this test exists for. '
            .'If the change is intended, rerun with UPDATE_ROUTE_ACCESS=1.',
        );
    }

    /**
     * A route with no permission is reachable by the newest dough maker on
     * their first day. Some are meant to be — «my own payslip» cannot ask
     * for a permission nobody but the owner holds — but each one is a
     * decision, and the snapshot above is where it is recorded.
     */
    public function test_nothing_is_unauthenticated_by_accident(): void
    {
        $open = array_keys(array_filter($this->matrix(), fn ($who) => $who === '(public)'));

        $expected = array_keys(self::PUBLIC_ROUTES);
        sort($expected);

        $this->assertSame(
            $expected,
            $open,
            'A route is open to the world. Either give it auth, or add it to '
            .'PUBLIC_ROUTES with the reason it has to be public.',
        );
    }

    /**
     * The other half of the door.
     *
     * Four routes take an id and ask for no permission, because they are
     * somebody's own — «withdraw my request», «sign this handset out». A
     * permission cannot express «mine», so the controller has to, and
     * nothing was checking that it still did. Both of these were written
     * correctly and neither was covered: the check could have been
     * deleted in a refactor and every test would still have passed, while
     * any employee could withdraw a colleague's request for money.
     */
    #[DataProvider('selfServiceRoutes')]
    public function test_a_self_service_route_will_not_serve_somebody_elses_record(
        string $model,
        string $path,
        array $attributes,
    ): void {
        $this->seed(BakerySeeder::class);

        $mine = User::factory()->create(['is_active' => true]);
        $mine->assignRole('seller');

        $theirs = User::factory()->create(['is_active' => true]);
        $theirs->assignRole('seller');

        $record = $model::create([
            'user_id' => $theirs->id,
            'status' => 'pending',
            ...$attributes,
        ]);

        $this->actingAs($mine, 'sanctum')
            ->deleteJson("/api/v1/{$path}/{$record->id}")
            ->assertForbidden();

        $this->assertDatabaseHas($record->getTable(), ['id' => $record->id]);
    }

    public static function selfServiceRoutes(): array
    {
        return [
            'an advance request' => [
                StaffAdvanceRequest::class,
                'advance-requests',
                ['amount' => 500_000, 'reason' => 'آزمایش'],
            ],
            // A wage request is asked for a month rather than an amount:
            // the sum is whatever the pay sheet works out when it is paid.
            'a wage request' => [
                SalaryPaymentRequest::class,
                'salary-requests',
                ['period_start' => '2026-08-23', 'note' => 'آزمایش'],
            ],
        ];
    }

    /**
     * A permission nobody holds is a route nobody can use, which is either
     * a typo in the middleware or a permission the seeder forgot to grant.
     * Both look like «it just doesn't work» from the shop floor.
     */
    public function test_no_route_is_locked_behind_a_permission_nobody_has(): void
    {
        $orphans = array_keys(array_filter($this->matrix(), fn ($who) => $who === '(nobody)'));

        $this->assertSame([], $orphans, 'These routes cannot be reached by any role.');
    }
}
