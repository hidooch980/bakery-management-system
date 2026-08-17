<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * How much flour is in the store, as the seller's screen asks for it.
 *
 * He could already see this — inside the sheet for selling flour, which
 * meant deciding to sell some in order to find out whether there was any.
 * It is the figure that answers «can we bake tomorrow», so it now sits on
 * the warehouse heading, and that heading reads this endpoint.
 *
 * Sacks lead the display because sacks are what arrive at the door and
 * what the quota is counted in — the shop's own words: «هر آرد ورودی کیسه
 * است نه ریال».
 */
class SellerSeesTheFlourOnHandTest extends TestCase
{
    use RefreshDatabase;

    private const BAG_KG = 40;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'flour_bag_weight_kg' => self::BAG_KG,
            'flour_price_per_kg' => 30_000,
            'currency' => 'toman',
        ]);
        Money::forgetCache();
    }

    private function seller(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('seller');

        return $user;
    }

    private function stock(float $kg): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', $kg, 'purchase');
    }

    private function onHand(): TestResponse
    {
        return $this->actingAs($this->seller(), 'sanctum')->getJson('/api/v1/flour-sales/options');
    }

    public function test_the_seller_may_ask(): void
    {
        $this->stock(1000);

        $this->onHand()->assertOk();
    }

    public function test_it_answers_in_sacks_and_in_kilos(): void
    {
        $this->stock(1000);

        $this->onHand()
            ->assertJsonPath('data.available_kg', 1000)
            // 1000 kg at a 40 kg sack.
            ->assertJsonPath('data.available_bags', 25)
            ->assertJsonPath('data.bag_weight_kg', self::BAG_KG);
    }

    public function test_an_empty_store_says_nothing_is_there(): void
    {
        // Not an error and not a dash: zero sacks is an answer, and it is
        // the one that stops tomorrow's baking.
        $this->onHand()
            ->assertOk()
            ->assertJsonPath('data.available_kg', 0)
            ->assertJsonPath('data.available_bags', 0);
    }

    public function test_baking_takes_it_down(): void
    {
        $this->stock(1000);

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('out', 400, 'production');

        $this->onHand()
            ->assertJsonPath('data.available_kg', 600)
            ->assertJsonPath('data.available_bags', 15);
    }

    public function test_flour_lent_to_a_partner_is_gone_from_the_store(): void
    {
        $this->stock(1000);

        // Consignment flour is still owed back, but it is not on the
        // premises — and the question this figure answers is whether there
        // is flour to bake with, not who owns it.
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('out', 200, 'consignment_out');

        $this->onHand()->assertJsonPath('data.available_bags', 20);
    }

    public function test_a_part_sack_is_not_rounded_away(): void
    {
        $this->stock(50);

        // 50 kg is a sack and a quarter. Rounding it to one would tell the
        // shop it has less than it does; rounding to two, more.
        $this->onHand()->assertJsonPath('data.available_bags', 1.25);
    }
}
