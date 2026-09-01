<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\FlourSale;
use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correcting a flour sale moves the flour, and cancelling one gives back
 * what actually left.
 *
 * Two faults, the second worse than the first. The model took stock out
 * when a sale was created and put it back when one was deleted, and had
 * nothing for the edit in between — so a corrected quantity recomputed
 * the weight and the money and left the store alone.
 *
 * And the reversal moved back `weight_kg` as it stood at the moment of
 * deletion. For an edited sale that is not what went out: ten kilos sold,
 * corrected to a hundred, then cancelled put a hundred back and made
 * ninety kilos of flour out of nothing.
 *
 * The same rule as the consignment record now: what the sale should have
 * done to the store is read against what the ledger says it did, and the
 * difference is posted once.
 */
class CorrectingAFlourSaleMovesTheFlourTest extends TestCase
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
        $this->actingAs($this->admin);

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 1000, 'purchase');
    }

    private function balance(): float
    {
        return InventoryItem::ofKey(InventoryItem::FLOUR)->fresh()->balance;
    }

    private function sale(float $quantity, string $unit = FlourSale::KG): FlourSale
    {
        return FlourSale::create([
            'user_id' => $this->admin->id,
            'unit' => $unit,
            'quantity' => $quantity,
            'unit_price' => 1200,
            'sold_on' => now()->toDateString(),
        ]);
    }

    public function test_raising_the_quantity_takes_the_difference_out(): void
    {
        $sale = $this->sale(10);

        $this->assertEqualsWithDelta(990, $this->balance(), 0.01);

        $sale->update(['quantity' => 100]);

        // Not 990. The books had sold a hundred and the shelf still held
        // ninety of it.
        $this->assertEqualsWithDelta(900, $this->balance(), 0.01);
    }

    public function test_lowering_the_quantity_puts_the_difference_back(): void
    {
        $sale = $this->sale(100);

        $this->assertEqualsWithDelta(900, $this->balance(), 0.01);

        $sale->update(['quantity' => 10]);

        $this->assertEqualsWithDelta(990, $this->balance(), 0.01);
    }

    public function test_cancelling_an_edited_sale_gives_back_only_what_left(): void
    {
        $sale = $this->sale(10);
        $sale->update(['quantity' => 100]);

        $this->assertEqualsWithDelta(900, $this->balance(), 0.01);

        $sale->delete();

        // A thousand, exactly. The old reversal put back today's weight
        // and would have left 1090 here — ninety kilos from nowhere.
        $this->assertEqualsWithDelta(1000, $this->balance(), 0.01);
    }

    public function test_switching_to_sacks_moves_the_weight_a_sack_carries(): void
    {
        $sale = $this->sale(2);

        $this->assertEqualsWithDelta(998, $this->balance(), 0.01);

        // Two sacks, not two kilos.
        $sale->update(['unit' => FlourSale::BAG]);

        $this->assertEqualsWithDelta(920, $this->balance(), 0.01);
    }

    public function test_an_edit_that_changes_no_weight_moves_nothing(): void
    {
        $sale = $this->sale(10);
        $before = $this->balance();

        $sale->update(['unit_price' => 1500]);

        $this->assertEqualsWithDelta($before, $this->balance(), 0.01);
    }

    public function test_cancelling_an_untouched_sale_still_gives_it_all_back(): void
    {
        $sale = $this->sale(40);

        $this->assertEqualsWithDelta(960, $this->balance(), 0.01);

        $sale->delete();

        $this->assertEqualsWithDelta(1000, $this->balance(), 0.01);
    }
}
