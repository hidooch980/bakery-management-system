<?php

namespace Tests\Feature;

use App\Http\Middleware\PicksTheBakeryInThePanel;
use App\Models\Bakery;
use App\Models\User;
use App\Support\CurrentBakery;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An owner who holds two shops, looking at the second one.
 *
 * The phone has been able to do this since owners could hold more than
 * one shop: it names the shop in a header. The panel could not. An owner
 * could open a second bakery from «نانوایی جدید» and then had no way to
 * see it — every screen went on answering for `users.bakery_id`.
 */
class TheOwnerSwitchesShopsInThePanelTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Bakery $home;

    private Bakery $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->home = Bakery::query()->oldest('id')->first();
        $this->other = Bakery::create(['name' => 'نانوایی دوم']);

        $this->owner = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $this->home->id,
        ]);
        $this->owner->assignRole('admin');
        $this->owner->bakeries()->attach($this->other->id);

        CurrentBakery::forget();
    }

    public function test_the_panel_answers_for_the_shop_the_session_names(): void
    {
        $this->actingAs($this->owner)
            ->withSession([PicksTheBakeryInThePanel::KEY => $this->other->id])
            ->get('/admin')
            ->assertSuccessful();

        // The switch has to reach the global scopes, not merely the
        // topbar, or the screens would name one shop and show another's
        // figures.
        $this->assertSame($this->other->id, CurrentBakery::id());
    }

    public function test_switching_is_remembered_for_the_next_page(): void
    {
        $this->actingAs($this->owner)
            ->from('/admin')
            ->post(route('panel.shop.switch'), ['bakery_id' => $this->other->id])
            ->assertRedirect('/admin')
            ->assertSessionHas(PicksTheBakeryInThePanel::KEY, $this->other->id);
    }

    public function test_a_shop_this_person_cannot_reach_is_refused(): void
    {
        $stranger = Bakery::create(['name' => 'نانوایی غریبه']);

        $this->actingAs($this->owner)
            ->post(route('panel.shop.switch'), ['bakery_id' => $stranger->id])
            ->assertForbidden();

        $this->assertNull(session(PicksTheBakeryInThePanel::KEY));
    }

    /**
     * Access taken away after the switch was made.
     *
     * The session outlives the permission, so the id is checked on every
     * page rather than at the moment it is stored.
     */
    public function test_a_session_naming_a_shop_they_lost_is_dropped(): void
    {
        $this->owner->bakeries()->detach($this->other->id);

        $this->actingAs($this->owner)
            ->withSession([PicksTheBakeryInThePanel::KEY => $this->other->id])
            ->get('/admin')
            ->assertSuccessful()
            ->assertSessionMissing(PicksTheBakeryInThePanel::KEY);

        $this->assertSame($this->home->id, CurrentBakery::id());
    }

    public function test_someone_with_one_shop_is_not_offered_a_switcher(): void
    {
        $seller = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $this->home->id,
        ]);
        $seller->assignRole('admin');

        $this->actingAs($seller)
            ->get('/admin')
            ->assertSuccessful()
            ->assertDontSee('fi-topbar-shop');
    }

    public function test_the_owner_is_offered_both_shops_by_name(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin')
            ->assertSuccessful()
            ->assertSee('fi-topbar-shop')
            ->assertSee($this->home->name, escape: false)
            ->assertSee($this->other->name, escape: false);
    }
}
