<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\FlourPrice;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Ledger;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shop carried one flour price and the cost of goods read it for every
 * period, so entering today's higher price rewrote last month's profit —
 * and the partners' split with it. A bake is now costed at the price in
 * force on the day it happened.
 */
class DatedFlourPriceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function bake(float $kg, string $on): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', $kg + 10, 'purchase', $this->admin->id);

        $movement = $flour->move('out', $kg, 'production', $this->admin->id);
        // Backdated so the price lookup has a real day to work with.
        $movement->forceFill(['created_at' => $on])->save();
    }

    public function test_a_bake_is_costed_at_the_price_of_its_own_day(): void
    {
        FlourPrice::create(['price_per_kg' => 20_000, 'effective_from' => '2026-07-01']);
        FlourPrice::create(['price_per_kg' => 30_000, 'effective_from' => '2026-08-01']);

        $this->bake(10, '2026-07-15 10:00:00');

        // July flour at the July price, whatever August costs.
        $this->assertEquals(
            200_000.0,
            Ledger::flourConsumedCost(
                now()->parse('2026-07-01')->startOfDay(),
                now()->parse('2026-07-31')->endOfDay(),
            ),
        );
    }

    public function test_a_later_rise_does_not_reach_back(): void
    {
        FlourPrice::create(['price_per_kg' => 20_000, 'effective_from' => '2026-07-01']);
        $this->bake(10, '2026-07-15 10:00:00');

        $july = fn () => Ledger::flourConsumedCost(
            now()->parse('2026-07-01')->startOfDay(),
            now()->parse('2026-07-31')->endOfDay(),
        );

        $before = $july();

        // The factory puts its price up in August.
        FlourPrice::create(['price_per_kg' => 45_000, 'effective_from' => '2026-08-01']);

        // A settled month's profit must not move under the owner's feet.
        $this->assertEquals($before, $july());
    }

    public function test_the_price_in_force_is_the_newest_one_not_after_the_day(): void
    {
        FlourPrice::create(['price_per_kg' => 20_000, 'effective_from' => '2026-07-01']);
        FlourPrice::create(['price_per_kg' => 30_000, 'effective_from' => '2026-08-01']);

        $this->assertEquals(20_000.0, FlourPrice::onDate(now()->parse('2026-07-31')));
        // The day it takes effect counts as the new price, not the old.
        $this->assertEquals(30_000.0, FlourPrice::onDate(now()->parse('2026-08-01')));
    }

    public function test_flour_bought_before_any_price_was_recorded_falls_back(): void
    {
        // An install that never opened the prices page still gets a figure
        // from the single setting rather than silently costing nothing.
        Bakery::first()->update(['flour_purchase_price_per_kg' => 15_000]);
        $this->bake(10, '2026-07-15 10:00:00');

        $this->assertEquals(
            150_000.0,
            Ledger::flourConsumedCost(
                now()->parse('2026-07-01')->startOfDay(),
                now()->parse('2026-07-31')->endOfDay(),
            ),
        );
    }

    public function test_two_bakes_either_side_of_a_rise_are_costed_apart(): void
    {
        FlourPrice::create(['price_per_kg' => 20_000, 'effective_from' => '2026-07-01']);
        FlourPrice::create(['price_per_kg' => 30_000, 'effective_from' => '2026-08-01']);

        $this->bake(10, '2026-07-20 10:00:00');
        $this->bake(10, '2026-08-05 10:00:00');

        // 200,000 of July flour plus 300,000 of August flour.
        $this->assertEquals(
            500_000.0,
            Ledger::flourConsumedCost(
                now()->parse('2026-07-01')->startOfDay(),
                now()->parse('2026-08-31')->endOfDay(),
            ),
        );
    }
}
