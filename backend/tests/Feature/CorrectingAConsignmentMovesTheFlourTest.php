<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing a consignment record moves the flour it says moved.
 *
 * It used not to. The model moved stock on `created` and `deleted` and on
 * settling, and on nothing else — so correcting the sacks squared the
 * ledger and left the real flour somewhere else. That is why the twelve
 * sacks from نانوایی کنت recorded as twelve kilograms had to be deleted
 * and rewritten rather than corrected: editing the number would have
 * fixed the books and lost 468 kg for ever.
 *
 * The rule is now one rule. What a consignment should have done to the
 * store is a fact about its current state — borrowed and not yet given
 * back is in the store, lent and not yet returned is out of it, settled
 * nets to nothing — and the difference from what it has actually moved is
 * posted as one correcting movement.
 */
class CorrectingAConsignmentMovesTheFlourTest extends TestCase
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

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 4000, 'purchase');
    }

    private function partner(): Customer
    {
        return Customer::create([
            'name' => 'نانوایی کنت',
            'type' => Customer::PARTNER_TYPE,
            'is_active' => true,
        ]);
    }

    private function balance(): float
    {
        return InventoryItem::ofKey(InventoryItem::FLOUR)->fresh()->balance;
    }

    private function record(string $direction, float $bags): ConsignmentFlour
    {
        return ConsignmentFlour::create([
            'customer_id' => $this->partner()->id,
            'direction' => $direction,
            'bags' => $bags,
            'occurred_on' => now()->toDateString(),
        ]);
    }

    public function test_the_twelve_kilogram_correction_now_works_by_editing(): void
    {
        // The real incident, in the shape it was actually typed: a sack
        // count entered into a weight field.
        $record = ConsignmentFlour::create([
            'customer_id' => $this->partner()->id,
            'direction' => 'borrowed',
            'amount_kg' => 12,
            'occurred_on' => now()->toDateString(),
        ]);

        $this->assertEqualsWithDelta(4012, $this->balance(), 0.01);

        // Corrected to what it was: twelve sacks.
        $record->update(['bags' => 12]);

        // 4000 + 480, not 4012 and not 4492. The store holds what the
        // partner actually handed over.
        $this->assertEqualsWithDelta(4480, $this->balance(), 0.01);
    }

    public function test_raising_the_sacks_on_borrowed_flour_raises_the_store(): void
    {
        $record = $this->record('borrowed', 5); // +200

        $this->assertEqualsWithDelta(4200, $this->balance(), 0.01);

        $record->update(['bags' => 8]); // should be +320

        $this->assertEqualsWithDelta(4320, $this->balance(), 0.01);
    }

    public function test_lowering_the_sacks_on_lent_flour_gives_some_back(): void
    {
        $record = $this->record('lent', 10); // -400

        $this->assertEqualsWithDelta(3600, $this->balance(), 0.01);

        $record->update(['bags' => 4]); // should be -160

        $this->assertEqualsWithDelta(3840, $this->balance(), 0.01);
    }

    public function test_correcting_which_way_the_flour_went_swings_it_both_ways(): void
    {
        $record = $this->record('lent', 5); // -200

        $this->assertEqualsWithDelta(3800, $this->balance(), 0.01);

        // It was received, not handed over.
        $record->update(['direction' => 'borrowed']); // should be +200

        $this->assertEqualsWithDelta(4200, $this->balance(), 0.01);
    }

    public function test_settling_still_nets_the_record_to_nothing(): void
    {
        $record = $this->record('borrowed', 5);

        $record->update(['settled_on' => now()->toDateString()]);

        // The sacks came in and went back, so the store is where it began.
        $this->assertEqualsWithDelta(4000, $this->balance(), 0.01);
    }

    public function test_a_settlement_recorded_by_mistake_can_be_taken_back(): void
    {
        $record = $this->record('borrowed', 5);
        $record->update(['settled_on' => now()->toDateString()]);

        $this->assertEqualsWithDelta(4000, $this->balance(), 0.01);

        // Nothing gave the flour back before: clearing the date left the
        // record saying the sacks were still here and the store saying
        // they were not.
        $record->update(['settled_on' => null]);

        $this->assertEqualsWithDelta(4200, $this->balance(), 0.01);
    }

    public function test_an_edit_that_changes_nothing_moves_nothing(): void
    {
        $record = $this->record('borrowed', 5);

        $before = $this->balance();
        $record->update(['note' => 'یادداشت']);

        $this->assertEqualsWithDelta($before, $this->balance(), 0.01);
    }

    public function test_deleting_a_corrected_record_still_takes_its_flour_with_it(): void
    {
        $record = $this->record('borrowed', 5);
        $record->update(['bags' => 9]);

        $this->assertEqualsWithDelta(4360, $this->balance(), 0.01);

        $record->delete();

        // Every movement it made, including the correction, comes back.
        $this->assertEqualsWithDelta(4000, $this->balance(), 0.01);
    }
}
