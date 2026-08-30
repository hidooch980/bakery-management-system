<?php

namespace Tests\Feature;

use App\Models\ConsignmentFlour;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\ProductionRecorder;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The warehouse is the only flour ledger.
 *
 * Flour used to be tracked in two places at once, and every route that
 * moved stock updated only one of them — so the two answered the same
 * question with different numbers. These tests pin the single-ledger
 * behaviour so it cannot quietly split in two again.
 */
class WarehouseSingleLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function flourBalance(): float
    {
        return InventoryItem::ofKey(InventoryItem::FLOUR)->balance;
    }

    public function test_the_flour_balance_endpoint_reads_the_warehouse(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/flour/balance')
            ->assertOk()
            ->assertJsonPath('data.balance_kg', fn ($v) => (float) $v === 500.0);

        $this->assertSame(500.0, $this->flourBalance());
    }

    public function test_recording_a_flour_movement_moves_the_warehouse(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/flour/movements', [
                'type' => 'in',
                'amount_kg' => 320,
                'note' => 'خرید سهمیه',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'in')
            ->assertJsonPath('data.amount_kg', fn ($v) => (float) $v === 320.0);

        // The whole point: one entry, one ledger, and the warehouse agrees.
        $this->assertSame(320.0, $this->flourBalance());

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/flour/balance')
            ->assertOk()
            ->assertJsonPath('data.balance_kg', fn ($v) => (float) $v === 320.0);
    }

    public function test_a_hand_entered_withdrawal_cannot_push_the_store_below_zero(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 50, 'purchase');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/flour/movements', ['type' => 'out', 'amount_kg' => 80])
            ->assertStatus(422);

        $this->assertSame(50.0, $this->flourBalance());
    }

    public function test_spray_flour_is_only_deducted_once(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 1000, 'purchase');
        // Kneading takes salt as well, so the store needs some of that too.
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 50, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        $before = $this->flourBalance();

        $dough = ProductionRecorder::dough(2, $this->admin->id);
        $afterKneading = $this->flourBalance();

        ProductionRecorder::chane($dough, $this->admin->id, 60, 0, 5, 70);

        // Kneading takes its flour, shaping takes the spray flour, and
        // neither is counted twice over.
        $this->assertSame(round($afterKneading - 5, 3), $this->flourBalance());
        $this->assertLessThan($before, $this->flourBalance());
    }

    // ------------------------------------------------ consignment flour

    public function test_consignment_flour_moves_the_warehouse_from_the_model(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 100, 'purchase');

        // Created directly, as the admin panel does — not through the API.
        $record = ConsignmentFlour::create([
            'user_id' => $this->admin->id,
            'partner_name' => 'نانوایی همسایه',
            'direction' => 'borrowed',
            'amount_kg' => 200,
            'occurred_on' => now(),
        ]);

        $this->assertSame(300.0, $this->flourBalance());

        // Settling hands the sacks back, so the store gives them up.
        $record->update(['settled_on' => now()]);
        $this->assertSame(100.0, $this->flourBalance());
    }

    public function test_lending_flour_from_the_panel_reduces_the_warehouse(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 400, 'purchase');

        ConsignmentFlour::create([
            'user_id' => $this->admin->id,
            'partner_name' => 'همکار',
            'direction' => 'lent',
            'amount_kg' => 150,
            'occurred_on' => now(),
        ]);

        $this->assertSame(250.0, $this->flourBalance());
    }

    public function test_partner_flour_is_counted_in_sacks(): void
    {
        // Nobody carries 200 kilos next door — they carry five sacks, and
        // the weight follows from the sack size in settings.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/consignment-flour', [
                'partner_name' => 'نانوایی همسایه',
                'direction' => 'borrowed',
                'bags' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('data.bags', 5)
            ->assertJsonPath('data.amount_kg', 200);

        $this->assertSame(200.0, $this->flourBalance());
    }

    public function test_a_weight_is_still_accepted_and_reads_back_in_sacks(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/consignment-flour', [
                'partner_name' => 'همکار',
                'direction' => 'borrowed',
                'amount_kg' => 100,
            ])
            ->assertCreated()
            // Half a sack at 40kg, said plainly rather than hidden.
            ->assertJsonPath('data.bags', 2.5);
    }

    public function test_the_partner_balance_is_reported_in_sacks(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/consignment-flour', [
                'partner_name' => 'همکار',
                'direction' => 'borrowed',
                'bags' => 3,
            ])->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/consignment-flour/balance')
            ->assertOk()
            ->assertJsonPath('data.borrowed_bags', 3)
            ->assertJsonPath('data.net_bags', -3);
    }

    public function test_flour_movements_can_be_recorded_in_sacks(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/flour/movements', ['type' => 'in', 'bags' => 8])
            ->assertCreated()
            ->assertJsonPath('data.bags', 8);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/flour/balance')
            ->assertOk()
            ->assertJsonPath('data.balance_bags', 8);

        $this->assertSame(320.0, $this->flourBalance());
    }

    public function test_deleting_a_consignment_record_takes_its_stock_with_it(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 100, 'purchase');

        $record = ConsignmentFlour::create([
            'user_id' => $this->admin->id,
            'partner_name' => 'همکار',
            'direction' => 'borrowed',
            'amount_kg' => 200,
            'occurred_on' => now(),
        ]);

        $this->assertSame(300.0, $this->flourBalance());

        $record->delete();

        // Otherwise the store keeps flour nothing on file accounts for.
        $this->assertSame(100.0, $this->flourBalance());
    }

    public function test_a_settled_consignment_can_still_be_deleted(): void
    {
        $record = ConsignmentFlour::create([
            'user_id' => $this->admin->id,
            'partner_name' => 'همکار',
            'direction' => 'borrowed',
            'amount_kg' => 200,
            'occurred_on' => now(),
        ]);

        $record->update(['settled_on' => now()]);
        $this->assertSame(0.0, $this->flourBalance());

        // The two movements cancel out, so undoing them must not fail on an
        // interim step that dips below zero on the way back to the same place.
        $record->delete();

        $this->assertSame(0.0, $this->flourBalance());
    }
}
