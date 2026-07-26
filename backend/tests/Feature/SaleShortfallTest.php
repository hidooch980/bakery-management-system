<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When a seller accounts for fewer loaves than the batch actually held, the
 * gap is a temporary debt against them — computed from the batch's own
 * count, never trusted from client input.
 */
class SaleShortfallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'flour_bag_weight_kg' => 40,
            'water_ratio' => 0.6,
            'salt_ratio' => 0.015,
            'dough_loss_ratio' => 0,
            'normal_chane_weight_kg' => 0.85,
            'bread_price' => 5000,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function chaneBatchOf(int $count): ChaneEntry
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 50, 'purchase');

        $dough = $this->userWithRole('dough_maker');
        $chane = $this->userWithRole('chane_gir');

        $this->actingAs($dough, 'sanctum')->postJson('/api/v1/dough-entries', ['bag_count' => 5]);

        $this->actingAs($chane, 'sanctum')->postJson('/api/v1/chane-entries', [
            'dough_entry_id' => DoughEntry::first()->id,
            'chane_count' => $count,
            'spray_flour_kg' => 0,
        ]);

        return ChaneEntry::first();
    }

    public function test_selling_fewer_loaves_than_the_batch_records_a_shortfall_debt(): void
    {
        $chane = $this->chaneBatchOf(100);
        $seller = $this->userWithRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chane->id,
                'payment_type' => 'cash',
                'bread_count' => 90,
            ])
            ->assertCreated();

        $sale = Sale::first();

        $this->assertSame(10, $sale->shortfall_count);
        // 10 loaves at the configured 5000 bread price.
        $this->assertEquals(50000.0, (float) $sale->shortfall_amount);
        $this->assertTrue($sale->has_shortfall);
        $this->assertNull($sale->shortfall_settled_on);
    }

    public function test_selling_the_whole_batch_leaves_no_shortfall(): void
    {
        $chane = $this->chaneBatchOf(100);
        $seller = $this->userWithRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chane->id,
                'payment_type' => 'cash',
                'bread_count' => 100,
            ])
            ->assertCreated();

        $sale = Sale::first();

        $this->assertNull($sale->shortfall_count);
        $this->assertNull($sale->shortfall_amount);
        $this->assertFalse($sale->has_shortfall);
    }

    public function test_the_shortfall_is_computed_from_the_batch_not_the_client(): void
    {
        $chane = $this->chaneBatchOf(100);
        $seller = $this->userWithRole('seller');

        // A malicious or mistaken client cannot claim its own shortfall
        // figure — only bread_count and the batch's real count matter.
        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chane->id,
                'payment_type' => 'cash',
                'bread_count' => 80,
                'shortfall_count' => 0,
                'shortfall_amount' => 0,
            ])
            ->assertCreated();

        $sale = Sale::first();

        $this->assertSame(20, $sale->shortfall_count);
        $this->assertEquals(100000.0, (float) $sale->shortfall_amount);
    }

    public function test_admin_settles_a_shortfall_in_the_panel(): void
    {
        $chane = $this->chaneBatchOf(50);
        $seller = $this->userWithRole('seller');

        $this->actingAs($seller, 'sanctum')->postJson('/api/v1/sales', [
            'chane_entry_id' => $chane->id,
            'payment_type' => 'cash',
            'bread_count' => 40,
        ]);

        $sale = Sale::first();

        $admin = $this->userWithRole('admin');
        $this->actingAs($admin);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

        \Livewire\Livewire::test(
            \App\Filament\Resources\SaleResource\Pages\ListSales::class
        )->callTableAction('settleShortfall', $sale);

        $this->assertNotNull($sale->fresh()->shortfall_settled_on);
    }
}
