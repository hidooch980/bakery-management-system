<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a seller can reach from their own screen.
 *
 * A shop this size often has one person on the floor, so the seller works
 * every step rather than a batch waiting for whoever nominally owns it.
 * These tests pin what that opens up — and, just as importantly, what it
 * does not: the money and the settings stay with the admin.
 */
class SellerWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 2000, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 100, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_WET)->move('in', 50, 'purchase');
    }

    public function test_a_seller_can_knead_and_shape_a_batch(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 2])
            ->assertCreated();

        $pending = $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/dough-entries/pending')
            ->assertOk()
            // The queue is paginated, so the rows sit one level in.
            ->json('data.data');

        $this->assertNotEmpty($pending);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $pending[0]['id'],
                'chane_count' => 100,
                'spray_flour_kg' => 3,
            ])
            ->assertCreated();
    }

    public function test_a_seller_sees_who_checked_in_today(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/reports/attendance')
            ->assertOk();
    }

    public function test_a_seller_can_record_flour_arriving(): void
    {
        $before = InventoryItem::ofKey(InventoryItem::FLOUR)->balance;

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/inventory/movements', [
                'item' => 'flour',
                'direction' => 'in',
                'bags' => 5,
                'reason' => 'purchase',
            ])
            ->assertCreated();

        $this->assertGreaterThan($before, InventoryItem::ofKey(InventoryItem::FLOUR)->balance);
    }

    public function test_a_seller_can_record_partner_flour(): void
    {
        $before = InventoryItem::ofKey(InventoryItem::FLOUR)->balance;

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/consignment-flour', [
                'partner_name' => 'نانوایی همسایه',
                'direction' => 'borrowed',
                'amount_kg' => 200,
            ])
            ->assertCreated();

        $this->assertSame($before + 200, InventoryItem::ofKey(InventoryItem::FLOUR)->balance);
    }

    // ------------------------------------------------- still off limits

    public function test_a_seller_still_cannot_reach_the_books(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/reports/financial')
            ->assertForbidden();

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/expenses', [
                'category' => 'fuel',
                'title' => 'گازوئیل',
                'amount' => 100000,
            ])
            ->assertForbidden();
    }

    public function test_a_seller_still_cannot_manage_staff_or_settings(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertForbidden();

        $this->actingAs($this->seller, 'sanctum')
            ->putJson('/api/v1/bakery', ['name' => 'تغییر'])
            ->assertForbidden();
    }
}
