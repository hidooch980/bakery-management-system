<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The same batch, recorded twice because the first did not look like it
 * landed.
 *
 * On 24 Mordad the seller pressed «ثبت خمیر» three times in thirty-five
 * minutes for one thirteen-bag batch. Recording dough takes flour, salt
 * and yeast out of the store the moment it is entered, so the two that
 * were never shaped spent 1,040 kg of flour that never left the sack —
 * and put the shop over its quota for the period.
 */
class DoughDoubleTapTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        // Kneading draws on all three, so all three have to be there or
        // the batch is refused for the wrong reason.
        foreach ([InventoryItem::FLOUR, InventoryItem::SALT, InventoryItem::YEAST_DRY] as $key) {
            InventoryItem::ofKey($key)->move('in', 10_000, 'purchase', $this->seller->id);
        }
    }

    private function record(int $bags, bool $force = false): TestResponse
    {
        return $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/dough-entries', [
                'bag_count' => $bags,
                ...($force ? ['force' => true] : []),
            ]);
    }

    public function test_the_first_batch_goes_in(): void
    {
        $this->record(13)->assertCreated();

        $this->assertSame(1, DoughEntry::count());
    }

    public function test_the_same_batch_again_is_refused(): void
    {
        $this->record(13)->assertCreated();

        $this->record(13)->assertStatus(409);

        $this->assertSame(1, DoughEntry::count());
    }

    public function test_the_refusal_spends_no_flour(): void
    {
        $this->record(13)->assertCreated();

        $after = (float) InventoryItem::ofKey(InventoryItem::FLOUR)->balance;

        $this->record(13)->assertStatus(409);

        // The whole point. A refused batch that still took the flour would
        // be the bug wearing a warning label.
        $this->assertEquals($after, (float) InventoryItem::ofKey(InventoryItem::FLOUR)->fresh()->balance);
    }

    public function test_a_genuinely_second_batch_gets_through_when_confirmed(): void
    {
        $this->record(13)->assertCreated();

        // Said yes to "is this a new batch?" — unusual, not impossible.
        $this->record(13, force: true)->assertCreated();

        $this->assertSame(2, DoughEntry::count());
    }

    public function test_a_different_size_is_not_a_repeat(): void
    {
        $this->record(13)->assertCreated();

        // A different size was never the double tap this guard was written
        // for, and it still is not — but on 1405/06/03 the owner asked for
        // one batch a day, and that rule sits in front of this one. The
        // second entry is now refused until somebody confirms it is
        // genuinely a second batch, whatever size it is.
        $this->record(10)->assertStatus(409);

        $this->record(10, force: true)->assertCreated();

        $this->assertSame(2, DoughEntry::count());
    }

    public function test_someone_elses_batch_does_not_block_yours(): void
    {
        $this->record(13)->assertCreated();

        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('dough_maker');

        // Reversed on 1405/06/03. The daily rule is deliberately about
        // the shop and not the person: the shop kneads once, whoever is
        // holding the phone, and two people recording the same morning is
        // the mistake it exists to catch. The double-tap guard beside it
        // is still per person — that one is about one thumb, this one is
        // about one bakery.
        $this->actingAs($other, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 13])
            ->assertStatus(409);

        $this->actingAs($other, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 13, 'force' => true])
            ->assertCreated();

        $this->assertSame(2, DoughEntry::count());
    }

    public function test_the_same_size_tomorrow_is_an_ordinary_day(): void
    {
        $this->record(13)->assertCreated();

        // Yesterday, not four hours ago. Four hours clears the double-tap
        // window but not the daily rule added on 1405/06/03, and «tomorrow
        // is an ordinary day» is only true of an actual tomorrow.
        DoughEntry::query()->update(['created_at' => now()->subDay()]);

        // This shop bakes thirteen bags most days. The guard is about a
        // double tap, not about ever baking the same amount twice.
        $this->record(13)->assertCreated();

        $this->assertSame(2, DoughEntry::count());
    }

    public function test_the_refusal_says_what_was_already_recorded(): void
    {
        $this->record(13)->assertCreated();

        $message = $this->record(13)->assertStatus(409)->json('message');

        // The size, so the person can tell at a glance whether this is the
        // batch they meant. Asserted as a substring rather than in full:
        // the elapsed time in it is phrased by the framework and would make
        // this test about wording rather than about the guard.
        $this->assertStringContainsString('13', $message);
        $this->assertStringContainsString('کیسه', $message);
    }
}
