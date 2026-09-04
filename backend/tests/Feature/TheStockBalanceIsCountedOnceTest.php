<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\CurrentBakery;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What «how much flour is there» costs, and that it is still true.
 *
 * The balance is derived from the movement ledger — two SUM queries — and
 * it is read wherever the shop asks that question. The answer page read it
 * twenty-three times in one request and paid forty-six queries for the
 * same number, on top of thirteen lookups of the item itself. That was
 * more than half of what `/today` cost.
 *
 * Both are remembered now, and the interesting half of this file is not
 * the saving. It is that a remembered stock figure must never be the one
 * from before the sack that was just booked in.
 */
class TheStockBalanceIsCountedOnceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_a_movement_changes_the_balance_it_is_read_after(): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);

        $flour->move(direction: 'in', quantity: 100, reason: 'purchase');

        // Read once, so it is remembered.
        $this->assertSame(100.0, $flour->balance);

        $flour->move(direction: 'out', quantity: 40, reason: 'production');

        // The whole risk of remembering it. A shop that books a sack in
        // and is told the figure from before is worse off than one that
        // pays for the query.
        $this->assertSame(60.0, $flour->balance);
    }

    public function test_the_same_item_asked_for_twice_is_one_lookup(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR);

        DB::flushQueryLog();
        DB::enableQueryLog();

        InventoryItem::ofKey(InventoryItem::FLOUR);
        InventoryItem::ofKey(InventoryItem::FLOUR);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // The item itself, which is what this remembers. Whichever shop is
        // current still gets worked out per call — `CurrentBakery` decides
        // that deliberately, because one request can act as users of
        // different shops — so the assertion names the table rather than
        // demanding silence.
        $items = collect($log)
            ->filter(fn ($q) => str_contains($q['query'], 'inventory_items'));

        $this->assertCount(0, $items, 'the item was looked up again');
    }

    public function test_a_second_shop_gets_its_own_item(): void
    {
        $ours = InventoryItem::ofKey(InventoryItem::FLOUR);

        $other = Bakery::create(['name' => 'نانوایی دوم']);

        $theirs = CurrentBakery::for($other->id, fn () => InventoryItem::ofKey(InventoryItem::FLOUR));

        // Keyed by shop as well as by name. One request can act as users
        // of different shops, and handing the second one the first's row
        // would be one bakery reading another's flour.
        $this->assertNotSame($ours->id, $theirs->id);
    }

    public function test_reading_the_balance_twice_counts_the_ledger_once(): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move(direction: 'in', quantity: 100, reason: 'purchase');
        $flour->balance;

        DB::flushQueryLog();
        DB::enableQueryLog();

        $flour->balance;
        $flour->balance;

        $this->assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();
    }

    public function test_the_answer_page_no_longer_counts_the_same_sack_forty_times(): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move(direction: 'in', quantity: 500, reason: 'purchase');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/today')
            ->assertOk();

        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        // It was 109 on an empty shop and 125 on a busy one, and this is
        // the page the owner opens first. Generous on purpose: the point
        // is to catch a return to counting the ledger per read, not to pin
        // a number that a harmless change would break.
        $this->assertLessThan(
            70,
            $queries,
            "the answer page took {$queries} queries",
        );
    }
}
