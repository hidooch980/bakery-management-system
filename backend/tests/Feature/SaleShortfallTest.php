<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\Sale;
use App\Models\User;
use App\Support\SaleRecorder;
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
            // Proving is measured in ProofGainTest; here the
            // formula's own arithmetic is what is under test.
            'proof_gain_ratio' => 0,
            'normal_chane_weight_kg' => 0.85,
            'bread_price' => 5000,
        ]);
    }

    public function test_a_named_shortfall_line_charges_the_seller(): void
    {
        $seller = $this->userWithRole('seller');
        $chane = $this->chaneBatchOf(100);

        // The seller says plainly that 10 loaves are unaccounted for,
        // rather than leaving them to be inferred from what is missing.
        SaleRecorder::record($chane, [
            ['payment_type' => 'cash', 'bread_count' => 90, 'amount' => 450_000,
                'customer_id' => null, 'note' => null],
            ['payment_type' => 'shortfall', 'bread_count' => 10, 'amount' => null,
                'customer_id' => null, 'note' => null],
        ], $seller->id);

        $named = Sale::where('payment_type', 'shortfall')->firstOrFail();

        $this->assertSame(10, (int) $named->shortfall_count);
        $this->assertSame('50000.00', $named->shortfall_amount);

        // Counted once for the batch: the cash line carries nothing, since
        // the lines already add up to every loaf.
        $cash = Sale::where('payment_type', 'cash')->firstOrFail();
        $this->assertNull($cash->shortfall_count);

        // And no money gap — a shortfall line was never expected to pay.
        $this->assertNull($named->amount_difference);
    }

    public function test_the_automatic_shortfall_still_catches_what_was_not_named(): void
    {
        $seller = $this->userWithRole('seller');
        $chane = $this->chaneBatchOf(100);

        // 80 sold, 5 named as short — the other 15 are still unaccounted
        // for and must not slip through because a line was named.
        SaleRecorder::record($chane, [
            ['payment_type' => 'shortfall', 'bread_count' => 5, 'amount' => null,
                'customer_id' => null, 'note' => null],
            ['payment_type' => 'cash', 'bread_count' => 80, 'amount' => 400_000,
                'customer_id' => null, 'note' => null],
        ], $seller->id);

        $named = Sale::where('payment_type', 'shortfall')->firstOrFail();
        $cash = Sale::where('payment_type', 'cash')->firstOrFail();

        $this->assertSame(5, (int) $named->shortfall_count);
        $this->assertSame(15, (int) $cash->shortfall_count);

        // 20 loaves in all, at 5,000 apiece.
        $total = Sale::where('user_id', $seller->id)->get()
            ->sum(fn (Sale $s) => $s->open_shortfall);

        $this->assertSame(100_000.0, round($total, 2));
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

        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_WET)->move('in', 50, 'purchase');
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
