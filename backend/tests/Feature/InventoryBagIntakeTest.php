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
 * Flour arrives in sacks, so the app counts sacks. What one weighs is a
 * shop setting, so the conversion happens on the server rather than in a
 * client that could be holding a stale figure.
 */
class InventoryBagIntakeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['flour_bag_weight_kg' => 40]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_flour_recorded_in_bags_is_stored_by_weight(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/inventory/movements', [
                'item' => 'flour',
                'direction' => 'in',
                'bags' => 5,
                'reason' => 'purchase',
            ])
            ->assertCreated();

        // 5 sacks at the configured 40kg.
        $this->assertSame(200.0, InventoryItem::ofKey('flour')->balance);
    }

    public function test_a_changed_bag_weight_is_used_for_the_next_intake(): void
    {
        Bakery::first()->update(['flour_bag_weight_kg' => 50]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/inventory/movements', [
                'item' => 'flour',
                'direction' => 'in',
                'bags' => 2,
                'reason' => 'purchase',
            ])
            ->assertCreated();

        $this->assertSame(100.0, InventoryItem::ofKey('flour')->balance);
    }

    /**
     * Salt sacks come in no fixed size, so a bag count would be guesswork.
     * It is weighed instead, and a bag entry is refused rather than
     * silently converted at some invented figure.
     */
    public function test_salt_is_recorded_by_weight_not_by_sacks(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/inventory/movements', [
                'item' => 'salt',
                'direction' => 'in',
                'bags' => 3,
                'reason' => 'purchase',
            ])
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/inventory/movements', [
                'item' => 'salt',
                'direction' => 'in',
                'quantity' => 75,
                'reason' => 'purchase',
            ])
            ->assertCreated();

        $this->assertSame(75.0, InventoryItem::ofKey('salt')->balance);
    }

    public function test_recording_by_weight_still_works(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/inventory/movements', [
                'item' => 'flour',
                'direction' => 'in',
                'quantity' => 123.5,
                'reason' => 'purchase',
            ])
            ->assertCreated();

        $this->assertSame(123.5, InventoryItem::ofKey('flour')->balance);
    }

    public function test_a_request_with_neither_bags_nor_a_weight_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/inventory/movements', [
                'item' => 'flour',
                'direction' => 'in',
                'reason' => 'purchase',
            ])
            ->assertStatus(422);
    }

    public function test_bags_are_refused_when_no_sack_size_is_configured(): void
    {
        Bakery::first()->update(['flour_bag_weight_kg' => 0]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/inventory/movements', [
                'item' => 'flour',
                'direction' => 'in',
                'bags' => 5,
                'reason' => 'purchase',
            ])
            ->assertStatus(422);

        // Guessing a sack weight would put a wrong figure in the ledger.
        $this->assertSame(0.0, InventoryItem::ofKey('flour')->balance);
    }

    /**
     * The seller books flour in themselves.
     *
     * They are often the only one on the floor when a delivery arrives, and
     * flour sitting unrecorded until an admin opens the panel is what put
     * the warehouse out of step in the first place.
     */
    public function test_a_seller_can_record_flour_arriving(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/inventory/movements', [
                'item' => 'flour',
                'direction' => 'in',
                'bags' => 5,
            ])
            ->assertCreated();
    }

    public function test_production_staff_still_cannot_record_stock(): void
    {
        $doughMaker = User::factory()->create(['is_active' => true]);
        $doughMaker->assignRole('dough_maker');

        $this->actingAs($doughMaker, 'sanctum')
            ->postJson('/api/v1/inventory/movements', [
                'item' => 'flour',
                'direction' => 'in',
                'bags' => 5,
            ])
            ->assertForbidden();
    }
}
