<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seven endpoints that took a user straight from the URL.
 *
 * Every other model carries a global bakery scope, so a query for one
 * shop cannot return another's rows. [User] deliberately does not, and
 * for a good reason: resolving the signed-in user is how the current
 * bakery is worked out, so scoping users by it would ask the question to
 * answer the question. The cost is that route model binding on a User is
 * unguarded, and seven endpoints never checked. Two of them delete and
 * update, one switches somebody off, and two settle a seller's account.
 * The seventh was found only by reading routes/api.php: a sweep of
 * controller signatures had missed `toggle-active`.
 *
 * With one shop on the box there was nothing to cross, which is exactly
 * why nobody noticed — and four more shops are built and waiting on the
 * owner's «بعد از تست نهایی». These tests make the second shop that
 * production does not have yet, because a guard nothing crosses is a
 * guard nobody can tell is broken.
 *
 * The answer is 404 and not 403 throughout: a refusal that says «you may
 * not touch that» confirms the id exists and names a real person at
 * another shop.
 */
class OneShopCannotReachIntoAnotherTest extends TestCase
{
    use RefreshDatabase;

    private User $ourAdmin;

    private User $theirSeller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $ours = Bakery::first();
        $theirs = Bakery::create(['name' => 'نانوایی دوم']);

        $this->ourAdmin = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $ours->id,
        ]);
        $this->ourAdmin->assignRole('admin');

        $this->theirSeller = User::factory()->create([
            'name' => 'فروشندهٔ مغازهٔ دیگر',
            'is_active' => true,
            'bakery_id' => $theirs->id,
        ]);
        $this->theirSeller->assignRole('seller');
    }

    private function asUs(): self
    {
        $this->actingAs($this->ourAdmin, 'sanctum');

        return $this;
    }

    public function test_another_shops_user_cannot_be_read(): void
    {
        $this->asUs()
            ->getJson("/api/v1/users/{$this->theirSeller->id}")
            ->assertNotFound();
    }

    public function test_another_shops_user_cannot_be_edited(): void
    {
        $this->asUs()
            ->putJson("/api/v1/users/{$this->theirSeller->id}", ['name' => 'تغییر یافته'])
            ->assertNotFound();

        // The name is what would have moved, so it is what is checked.
        $this->assertSame('فروشندهٔ مغازهٔ دیگر', $this->theirSeller->fresh()->name);
    }

    public function test_another_shops_user_cannot_be_deleted(): void
    {
        $this->asUs()
            ->deleteJson("/api/v1/users/{$this->theirSeller->id}")
            ->assertNotFound();

        $this->assertNotNull($this->theirSeller->fresh());
    }

    public function test_another_shops_seller_cannot_be_settled(): void
    {
        // Settling moves money and writes a bank posting. Reaching across
        // shops here would put one shop's cash on another's balance.
        $this->asUs()
            ->postJson("/api/v1/seller-accounts/{$this->theirSeller->id}/settle")
            ->assertNotFound();
    }

    public function test_another_shops_seller_cannot_be_settled_in_loaves(): void
    {
        $this->asUs()
            ->postJson("/api/v1/seller-accounts/{$this->theirSeller->id}/settle-loaves", [
                'loaves' => 5,
            ])
            ->assertNotFound();
    }

    public function test_another_shops_staff_cannot_be_ticked_in(): void
    {
        $this->asUs()
            ->postJson("/api/v1/attendance/check-in/{$this->theirSeller->id}")
            ->assertNotFound();
    }

    public function test_another_shops_seller_has_no_performance_to_read(): void
    {
        // The sales themselves were already safe — Sale carries the global
        // scope, so the figures came back empty. What crossed was the
        // name, which is enough to tell one shop who works at another.
        $this->asUs()
            ->getJson("/api/v1/reports/sellers/{$this->theirSeller->id}")
            ->assertNotFound();
    }

    public function test_another_shops_staff_cannot_be_switched_off(): void
    {
        // Found only when the routes were read rather than guessed at: the
        // first sweep of controller signatures missed this one, and it is
        // the one that could stop somebody at another shop working.
        $this->asUs()
            ->patchJson("/api/v1/users/{$this->theirSeller->id}/toggle-active")
            ->assertNotFound();

        $this->assertTrue($this->theirSeller->fresh()->is_active);
    }

    public function test_our_own_people_are_still_reachable(): void
    {
        // The point that matters most: this must refuse the other shop and
        // nothing else. A guard that also blocks the ordinary case would
        // take the panel down on the day the second shop opens.
        $ourSeller = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $this->ourAdmin->bakery_id,
        ]);
        $ourSeller->assignRole('seller');

        $this->asUs()
            ->getJson("/api/v1/users/{$ourSeller->id}")
            ->assertOk();

        $this->asUs()
            ->getJson("/api/v1/reports/sellers/{$ourSeller->id}")
            ->assertOk();
    }
}
