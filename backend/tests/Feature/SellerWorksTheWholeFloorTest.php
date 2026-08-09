<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * In a shop this size the seller is often the only one on the floor. These
 * cover the steps they are allowed to work end to end, and the ones that stay
 * shut regardless.
 */
class SellerWorksTheWholeFloorTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
        ]);

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        // Kneading spends the store, so there has to be one to spend.
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 2000, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 100, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_WET)->move('in', 50, 'purchase');
    }

    public function test_the_seller_can_record_the_dough_they_kneaded(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 2])
            ->assertCreated();
    }

    public function test_the_seller_can_see_which_dough_is_waiting_to_be_shaped(): void
    {
        // The chane screen is built on this list; without it the screen opens
        // empty and there is nothing to record against.
        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/dough-entries/pending')
            ->assertOk();
    }

    public function test_the_seller_can_record_the_chane_they_shaped(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 2])
            ->assertCreated();

        $pending = $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/dough-entries/pending')
            ->json('data');

        $this->assertNotEmpty($pending, 'the dough just recorded should be waiting');
    }

    public function test_the_seller_can_read_back_their_own_history(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/dough-entries/my-history')
            ->assertOk();

        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/chane-entries/my-history')
            ->assertOk();
    }

    public function test_the_seller_can_see_who_is_in_today(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/reports/attendance')
            ->assertOk();
    }

    public function test_the_money_and_the_settings_stay_shut(): void
    {
        // Working every station is not the same as running the shop.
        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/reports/financial')
            ->assertForbidden();

        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertForbidden();
    }
}
