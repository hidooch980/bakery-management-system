<?php

namespace Tests\Feature;

use App\Http\Middleware\PicksTheBakery;
use App\Models\Bakery;
use App\Models\Expense;
use App\Models\User;
use App\Support\CurrentBakery;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An owner holding three shops, and the wall between them.
 *
 * A person belonged to exactly one bakery, because there was only ever
 * one. `users.bakery_id` answers «which shop is this request about» for
 * the whole system — every global scope on every model goes through it —
 * and it still does. The extra shops are listed beside it rather than
 * replacing it, so somebody with no extra shops behaves exactly as they
 * did before: that is every member of staff in the shop.
 *
 * The part that has to be right is not the switching. It is that naming
 * a shop is a *request* to look at it and never permission: the id
 * arrives from a phone, and without the check a header would be enough
 * to read another shop's money.
 */
class AnOwnerWithSeveralShopsTest extends TestCase
{
    use RefreshDatabase;

    private Bakery $home;

    private Bakery $second;

    private Bakery $strangers;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->home = Bakery::first();
        $this->second = Bakery::create(['name' => 'نانوایی دوم']);
        $this->strangers = Bakery::create(['name' => 'نانوایی کس دیگر']);

        $this->owner = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $this->home->id,
        ]);
        $this->owner->assignRole('admin');

        $this->owner->bakeries()->attach($this->second);
    }

    private function read(string $path, ?int $asBakery = null)
    {
        return $this->actingAs($this->owner, 'sanctum')->getJson(
            $path,
            $asBakery === null ? [] : [PicksTheBakery::HEADER => (string) $asBakery],
        );
    }

    public function test_the_shops_on_offer_are_their_own_and_the_ones_granted(): void
    {
        $names = $this->read('/api/v1/bakeries/mine')
            ->assertOk()
            ->json('data.bakeries.*.name');

        $this->assertContains($this->home->name, $names);
        $this->assertContains('نانوایی دوم', $names);
        $this->assertNotContains('نانوایی کس دیگر', $names);
    }

    public function test_their_own_shop_is_offered_first(): void
    {
        // The one they want nine times in ten. A switcher that opens on
        // somebody else's shop is one people learn to distrust.
        $first = $this->read('/api/v1/bakeries/mine')->json('data.bakeries.0.name');

        $this->assertSame($this->home->name, $first);
    }

    public function test_naming_a_granted_shop_moves_the_whole_request_to_it(): void
    {
        $this->read('/api/v1/bakery', $this->second->id)
            ->assertOk()
            ->assertJsonPath('data.name', 'نانوایی دوم');
    }

    public function test_naming_nothing_leaves_them_in_their_own_shop(): void
    {
        $this->read('/api/v1/bakery')
            ->assertOk()
            ->assertJsonPath('data.name', $this->home->name);
    }

    public function test_a_shop_they_were_never_given_is_ignored(): void
    {
        // Ignored rather than refused: their own shop is always a correct
        // answer, and a 403 in the middle of a sales screen helps nobody
        // standing at a counter.
        $this->read('/api/v1/bakery', $this->strangers->id)
            ->assertOk()
            ->assertJsonPath('data.name', $this->home->name);
    }

    public function test_a_shop_they_were_never_given_cannot_be_read_through(): void
    {
        // The one that matters. If the header were trusted, this would
        // come back with another shop's costs on it.
        //
        // Written with a positive half on purpose. The first version
        // asserted only the absence, read `data.*.title` on a paginated
        // response, got null, and passed just as happily with the
        // permission check deleted — a test that could only ever agree
        // with itself.
        $this->costIn($this->strangers, 'راز همسایه');
        $this->costIn($this->second, 'اجارهٔ شعبهٔ دوم');

        CurrentBakery::forget();

        // Granted shop: the cost is there, so the path and the switch
        // are both known to work.
        $this->assertContains(
            'اجارهٔ شعبهٔ دوم',
            $this->titlesSeenAs($this->second),
        );

        // Shop they were never given: their own costs, never these.
        $this->assertNotContains(
            'راز همسایه',
            $this->titlesSeenAs($this->strangers),
        );
    }

    private function costIn(Bakery $bakery, string $title): void
    {
        CurrentBakery::for($bakery->id, fn () => Expense::create([
            'title' => $title,
            'category' => 'rent',
            'amount' => 900_000,
            'spent_on' => now()->toDateString(),
        ]));
    }

    /** @return list<string> */
    private function titlesSeenAs(Bakery $bakery): array
    {
        $titles = $this->read('/api/v1/expenses', $bakery->id)
            ->assertOk()
            ->json('data.data.*.title');

        // A null here means the shape moved and the assertions below
        // would stop meaning anything.
        $this->assertIsArray($titles, 'پاسخ هزینه‌ها آن شکلی نیست که آزمون فرض کرده.');

        return $titles;
    }

    public function test_someone_with_one_shop_is_unaffected(): void
    {
        $baker = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $this->home->id,
        ]);
        $baker->assignRole('admin');

        $rows = $this->actingAs($baker, 'sanctum')
            ->getJson('/api/v1/bakeries/mine')
            ->assertOk()
            ->json('data.bakeries');

        $this->assertCount(1, $rows);

        // And a header they have no claim to changes nothing for them.
        $this->actingAs($baker, 'sanctum')
            ->getJson('/api/v1/bakery', [PicksTheBakery::HEADER => (string) $this->second->id])
            ->assertOk()
            ->assertJsonPath('data.name', $this->home->name);
    }

    public function test_a_shop_cannot_be_granted_to_the_same_person_twice(): void
    {
        // Two rows for one pair would show the shop twice in a switcher,
        // and the second would be unreachable and never noticed.
        $this->expectException(QueryException::class);

        $this->owner->bakeries()->attach($this->second);
    }
}
