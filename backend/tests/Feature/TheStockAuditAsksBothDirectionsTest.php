<?php

namespace Tests\Feature;

use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The three questions the warehouse audit asks.
 *
 * It asked one for a long time — whether every record that should have
 * moved stock did — and that one found sixty sacks of flour. The other two
 * came out of the owner asking for a consumption list on 2026-08-17:
 *
 *   1. does every record that spends stock have its movement?
 *   2. does every movement have a record, or was it given back?
 *   3. does every reversal point at what it actually undid?
 *
 * Reading only the link column, I twice told him flour was missing — 1,600
 * kg, then 560 — when every quantity had been returned. The link is not
 * evidence: `reverses_movement_id` arrived on 2026-08-16 and everything
 * cancelled before it has none.
 */
class TheStockAuditAsksBothDirectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->baker = User::factory()->create(['is_active' => true]);
        $this->baker->assignRole('admin');

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 4000, 'purchase', $this->baker->id);
    }

    private function flour(): InventoryItem
    {
        return InventoryItem::ofKey(InventoryItem::FLOUR);
    }

    public function test_a_clean_warehouse_passes(): void
    {
        $this->artisan('stock:audit')
            ->expectsOutputToContain('هر رکوردی که باید انبار را جابه‌جا می‌کرد، کرده است.')
            ->assertSuccessful();
    }

    public function test_a_record_that_moved_nothing_is_found(): void
    {
        // Straight to the model, bypassing ProductionRecorder — which is
        // exactly the mistake that lost sixty sacks.
        DoughEntry::create(['user_id' => $this->baker->id, 'bag_count' => 10]);

        $this->artisan('stock:audit')->assertFailed();
    }

    public function test_a_movement_whose_record_is_gone_and_was_given_back_is_fine(): void
    {
        $dough = DoughEntry::create(['user_id' => $this->baker->id, 'bag_count' => 10]);
        $this->flour()->move('out', 400, 'production', $this->baker->id, $dough);

        // Deleting goes through StockReversal, which puts the flour back.
        $dough->delete();

        // Nothing is missing: 400 out, 400 in. An audit that read the link
        // column would call this a 400 kg hole on any record cancelled
        // before that column existed.
        $this->artisan('stock:audit')->assertSuccessful();
    }

    public function test_a_movement_whose_record_is_gone_and_was_not_given_back_is_found(): void
    {
        $dough = DoughEntry::create(['user_id' => $this->baker->id, 'bag_count' => 10]);
        $movement = $this->flour()->move('out', 400, 'production', $this->baker->id, $dough);

        // Deleted around the model, so nothing reversed it. This is flour
        // spent by nobody, and until today nothing in the system asked.
        DB::table('dough_entries')->where('id', $dough->id)->delete();

        $this->assertNotNull(InventoryMovement::find($movement->id));
        $this->artisan('stock:audit')->assertFailed();
    }

    public function test_the_reversal_is_matched_by_quantity_not_by_the_link(): void
    {
        $dough = DoughEntry::create(['user_id' => $this->baker->id, 'bag_count' => 10]);
        $this->flour()->move('out', 400, 'production', $this->baker->id, $dough);
        $dough->delete();

        // As every reversal written before 2026-08-16 looks.
        InventoryMovement::query()->update(['reverses_movement_id' => null]);

        $this->artisan('stock:audit')->assertSuccessful();
    }

    public function test_two_identical_movements_need_two_reversals(): void
    {
        foreach ([1, 2] as $_) {
            $dough = DoughEntry::create(['user_id' => $this->baker->id, 'bag_count' => 10]);
            $this->flour()->move('out', 400, 'production', $this->baker->id, $dough);
            $dough->delete();
        }

        // Both were given back, so both are settled.
        InventoryMovement::query()->update(['reverses_movement_id' => null]);
        $this->artisan('stock:audit')->assertSuccessful();

        // Take one reversal away and exactly one movement is left unpaid —
        // a single reversal must not settle two identical movements.
        InventoryMovement::where('reason', 'production_reversal')->latest('id')->first()->delete();
        $this->artisan('stock:audit')->assertFailed();
    }

    public function test_a_reversal_pointing_at_a_living_record_is_found(): void
    {
        $kept = DoughEntry::create(['user_id' => $this->baker->id, 'bag_count' => 10]);
        $keptMovement = $this->flour()->move('out', 400, 'production', $this->baker->id, $kept);

        $gone = DoughEntry::create(['user_id' => $this->baker->id, 'bag_count' => 10]);
        $this->flour()->move('out', 400, 'production', $this->baker->id, $gone);
        $gone->delete();

        // What the backfill did on production: same item, same quantity,
        // first candidate — and this shop bakes 400 kg over and over.
        InventoryMovement::where('reason', 'production_reversal')
            ->update(['reverses_movement_id' => $keptMovement->id]);

        // Nothing deleted the kept entry, so nothing reversed its movement.
        // No stock is wrong, which is why nothing else notices; what is
        // wrong is which quota period gets the refund.
        $this->artisan('stock:audit')
            ->expectsOutputToContain('ابطال به حرکت اشتباه وصل است')
            ->assertFailed();
    }

    public function test_a_chane_entry_with_no_spray_flour_is_not_a_gap(): void
    {
        $dough = DoughEntry::create(['user_id' => $this->baker->id, 'bag_count' => 10]);

        $this->flour()->move('out', 400, 'production', $this->baker->id, $dough);

        ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->baker->id,
            'chane_count' => 470,
            'normal_weight_kg' => 399.5,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'sold',
        ]);

        // It dusted the bench with nothing, so it owes the warehouse
        // nothing.
        $this->artisan('stock:audit')->assertSuccessful();
    }
}
