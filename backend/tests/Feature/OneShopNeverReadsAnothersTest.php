<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Bakery;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\StaffAdvance;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentBakery;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every readable route, with two shops on the books.
 *
 * The wall between shops is the thing this system must get right before
 * it is ever sold to a second bakery, and it is not the sort of thing
 * that can be reasoned about one endpoint at a time: 46 of the 51 models
 * carry the scope, which sounds like enough until the five that do not
 * turn out to include `User`.
 *
 * So it is swept rather than argued. Every shop-B row carries the same
 * distinctive mark, and every parameterless GET is called as shop A's
 * owner. The mark must not come back.
 *
 * It found two the first time it ran:
 *
 *     ✗ GET api/v1/attendance/roster
 *     ✗ GET api/v1/sales/staff
 *
 * Both listed every active person in the database, so one shop's owner
 * read another shop's staff by name.
 */
class OneShopNeverReadsAnothersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Distinctive enough that it cannot appear by chance, and plain ASCII
     * so it survives whatever encoding a response is built with.
     */
    private const MARK = 'ZZOTHERSHOPZZ';

    private User $ourOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $ours = Bakery::create(['name' => 'نانوایی ما', 'currency' => 'toman']);
        $theirs = Bakery::create(['name' => 'نانوایی دیگر '.self::MARK, 'currency' => 'toman']);
        Money::forgetCache();

        $this->fillTheOtherShop($theirs);

        CurrentBakery::actAs($ours->id);

        $this->ourOwner = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $ours->id,
            'name' => 'مالکِ ما',
        ]);
        $this->ourOwner->assignRole('admin');

        // Nothing ambient left set: a request resolves its own shop from
        // the signed-in user, and leaving one forced here would test the
        // test rather than the code.
        CurrentBakery::forget();
    }

    /**
     * A shop with enough on its books that endpoints which only list
     * people who owe something have somebody to list.
     *
     * Learnt by running: with no advance on file, the payroll endpoint
     * reported no leak from a query that had no shop filter at all — its
     * rows were dropped by a `> 0` filter rather than by any wall. A
     * sweep is only as honest as the shop it sweeps against.
     */
    private function fillTheOtherShop(Bakery $theirs): void
    {
        CurrentBakery::actAs($theirs->id);

        $owner = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $theirs->id,
            'name' => 'مالکِ دیگر '.self::MARK,
        ]);
        $owner->assignRole('admin');

        Customer::create(['name' => 'مشتریِ دیگر '.self::MARK, 'type' => 'partner']);
        Supplier::create(['name' => 'آسیابِ دیگر '.self::MARK]);

        Expense::create([
            'category' => 'fuel',
            'title' => 'هزینهٔ دیگر '.self::MARK,
            'amount' => 1_000_000,
            'spent_on' => now(),
            'user_id' => $owner->id,
        ]);

        StaffAdvance::create([
            'user_id' => $owner->id,
            'recorded_by' => $owner->id,
            'amount' => 500_000,
            'paid_on' => now(),
        ]);

        Attendance::create([
            'user_id' => $owner->id,
            'date' => now()->toDateString(),
            'checked_in_at' => now(),
        ]);
    }

    /** @return list<string> every parameterless GET under the api */
    private function readableRoutes(): array
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            // Routes taking an id are left out: reaching another shop's
            // row by guessing its number is a different question, and
            // answering it here would mean inventing ids rather than
            // sweeping what a signed-in owner can simply open.
            if (! str_starts_with($uri, 'api/v1') || str_contains($uri, '{')) {
                continue;
            }

            if (in_array('GET', $route->methods(), true)) {
                $uris[] = $uri;
            }
        }

        sort($uris);

        return $uris;
    }

    public function test_no_readable_route_returns_another_shops_rows(): void
    {
        $leaks = [];

        foreach ($this->readableRoutes() as $uri) {
            $response = $this->actingAs($this->ourOwner, 'sanctum')->getJson('/'.$uri);

            if (str_contains($response->getContent(), self::MARK)) {
                $leaks[] = 'GET '.$uri;
            }
        }

        $this->assertSame(
            [],
            $leaks,
            'این مسیرها دادهٔ نانوایی دیگر را برگرداندند: '.implode('، ', $leaks)
        );
    }

    public function test_the_sweep_actually_reaches_the_routes(): void
    {
        // A sweep that 403s its way through every route reports no leak
        // and proves nothing. This is what makes the assertion above mean
        // something.
        $reached = 0;
        $routes = $this->readableRoutes();

        foreach ($routes as $uri) {
            if ($this->actingAs($this->ourOwner, 'sanctum')->getJson('/'.$uri)->status() === 200) {
                $reached++;
            }
        }

        $this->assertGreaterThan(80, $reached, "only {$reached} routes answered");
        $this->assertGreaterThan(90, count($routes));
    }

    public function test_the_mark_is_really_there_to_be_found(): void
    {
        // The other shop's owner sees their own rows. Without this, a
        // sweep that found nothing might be sweeping an empty shop.
        $theirOwner = User::withoutGlobalScopes()
            ->where('name', 'like', '%'.self::MARK.'%')
            ->firstOrFail();

        $response = $this->actingAs($theirOwner, 'sanctum')
            ->getJson('/api/v1/sales/staff');

        $this->assertStringContainsString(self::MARK, $response->getContent());
    }
}
