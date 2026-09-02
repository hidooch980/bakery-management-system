<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Support\ProductionRecorder;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correcting a production entry moves the stock it says moved.
 *
 * For a long time it did not. Both models moved the warehouse when a
 * record was created and when it was deleted, and on nothing in between —
 * so a number could be corrected while the goods stayed where they were.
 *
 * On 1405/06/07 a batch was entered as ten sacks, moved its 400 kg, and
 * was corrected to twenty three minutes later. The flour for the other ten
 * sacks was kneaded, shaped into 1,514 chane and sold, and never left the
 * ledger; the store believed it held 400 kg it did not have. Nothing
 * complained — `stock:audit` asked whether the entry had moved *any* flour,
 * and it had.
 *
 * This is the fourth bug of that shape in this project, which is why the
 * rule now lives in one place — `StockLedger` — and every model that
 * spends stock shares it.
 */
class EditingAnEntryMovesTheStockTest extends TestCase
{
    use RefreshDatabase;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'flour_bag_weight_kg' => 40,
            'water_ratio' => 0.7,
            'salt_ratio' => 0.016,
            'yeast_ratio' => 0.0025,
            'dough_loss_ratio' => 0,
            'proof_gain_ratio' => 0,
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
        ]);

        $this->baker = User::factory()->create(['is_active' => true]);
        $this->baker->assignRole('admin');
        $this->actingAs($this->baker);

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 4000, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 200, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 100, 'purchase');
    }

    private function flour(): float
    {
        return InventoryItem::ofKey(InventoryItem::FLOUR)->balance;
    }

    /** What one record has taken out of an item, net of anything put back. */
    private function movedBy(object $record, string $itemKey): float
    {
        $item = InventoryItem::ofKey($itemKey);
        $net = 0.0;

        foreach (InventoryMovement::where('source_type', $record::class)
            ->where('source_id', $record->getKey())
            ->where('inventory_item_id', $item->getKey())
            ->get() as $movement) {
            $net += ($movement->direction === 'out' ? 1 : -1) * (float) $movement->quantity;
        }

        return round($net, 3);
    }

    /**
     * The batch of 1405/06/07, reproduced: ten sacks entered, corrected to
     * twenty a few minutes later.
     */
    public function test_raising_the_sack_count_moves_the_extra_flour(): void
    {
        $dough = ProductionRecorder::dough(10, $this->baker->id);

        $this->assertSame(3600.0, $this->flour());
        $this->assertSame(400.0, $this->movedBy($dough, InventoryItem::FLOUR));

        $dough->update(['bag_count' => 20]);

        $this->assertSame(3200.0, $this->flour(), 'the other ten sacks never left the ledger');
        $this->assertSame(800.0, $this->movedBy($dough, InventoryItem::FLOUR));

        // Salt and yeast were kneaded into it too, and scale with it.
        $this->assertSame(12.8, $this->movedBy($dough, InventoryItem::SALT));
        $this->assertSame(2.0, $this->movedBy($dough, InventoryItem::YEAST_DRY));
    }

    public function test_lowering_the_sack_count_puts_flour_back(): void
    {
        $dough = ProductionRecorder::dough(20, $this->baker->id);

        $this->assertSame(3200.0, $this->flour());

        $dough->update(['bag_count' => 10]);

        $this->assertSame(3600.0, $this->flour());
        $this->assertSame(400.0, $this->movedBy($dough, InventoryItem::FLOUR));
    }

    /**
     * Nothing is rewritten: the original movement still says what happened
     * on the day, and the correction sits beside it.
     */
    public function test_the_original_movement_is_left_standing(): void
    {
        $dough = ProductionRecorder::dough(10, $this->baker->id);
        $dough->update(['bag_count' => 20]);

        $flour = InventoryMovement::where('source_type', DoughEntry::class)
            ->where('source_id', $dough->id)
            ->where('inventory_item_id', InventoryItem::ofKey(InventoryItem::FLOUR)->getKey())
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $flour);
        $this->assertSame('400.000', $flour[0]->quantity);
        $this->assertSame('400.000', $flour[1]->quantity);
        $this->assertSame('out', $flour[1]->direction);
    }

    /**
     * A batch that never moved stock has no rate to scale from, and
     * inventing one would conjure flour out of the air. `stock:audit`
     * reports these separately; a correction for them has to carry the
     * date it belongs to, which a movement stamped today would not.
     */
    public function test_a_batch_that_moved_nothing_is_left_alone(): void
    {
        $dough = DoughEntry::create([
            'user_id' => $this->baker->id,
            'bag_count' => 10,
            'yeast_type' => 'dry',
            'status' => 'pending',
        ]);

        $before = $this->flour();

        $dough->update(['bag_count' => 20]);

        $this->assertSame($before, $this->flour());
        $this->assertSame(0.0, $this->movedBy($dough, InventoryItem::FLOUR));
    }

    public function test_editing_anything_but_the_sacks_moves_nothing(): void
    {
        $dough = ProductionRecorder::dough(10, $this->baker->id);
        $before = $this->flour();

        $dough->update(['note' => 'خمیر شب', 'status' => 'processed']);

        $this->assertSame($before, $this->flour());
        $this->assertCount(3, InventoryMovement::where('source_type', DoughEntry::class)
            ->where('source_id', $dough->id)->get());
    }

    /**
     * Spray flour, the same rule from the other end: seven chane entries
     * had been edited without it, and every one still carried the 5 kg
     * from the moment it was written.
     */
    public function test_clearing_the_spray_flour_puts_it_back(): void
    {
        $dough = ProductionRecorder::dough(10, $this->baker->id);
        $chane = ProductionRecorder::chane($dough, $this->baker->id, 400.0, 0.0, 5.0, 470);

        $this->assertSame(3595.0, $this->flour());

        $chane->update(['spray_flour_kg' => 0]);

        $this->assertSame(3600.0, $this->flour());
        $this->assertSame(0.0, $this->movedBy($chane, InventoryItem::FLOUR));
    }

    public function test_raising_the_spray_flour_takes_more(): void
    {
        $dough = ProductionRecorder::dough(10, $this->baker->id);
        $chane = ProductionRecorder::chane($dough, $this->baker->id, 400.0, 0.0, 5.0, 470);

        $chane->update(['spray_flour_kg' => 40]);

        $this->assertSame(3560.0, $this->flour());
        $this->assertSame(40.0, $this->movedBy($chane, InventoryItem::FLOUR));
    }

    /**
     * Spray is a weight, not a count, so it is taken straight from the
     * column — including from nought, where a batch that dusted the bench
     * after the fact has nothing to scale from but is still owed the flour.
     */
    public function test_spray_flour_added_after_the_fact_still_moves(): void
    {
        $dough = ProductionRecorder::dough(10, $this->baker->id);
        $chane = ProductionRecorder::chane($dough, $this->baker->id, 400.0, 0.0, 0.0, 470);

        $this->assertSame(0.0, $this->movedBy($chane, InventoryItem::FLOUR));

        $chane->update(['spray_flour_kg' => 5]);

        $this->assertSame(3595.0, $this->flour());
        $this->assertSame(5.0, $this->movedBy($chane, InventoryItem::FLOUR));
    }

    /**
     * The whole chain at once, in the shape the shop's own records take: a
     * batch shaped and sprayed, then the sack count corrected. The chane
     * entry's spray must not be disturbed by the batch's correction — they
     * are separate records and separate flour.
     */
    public function test_correcting_the_batch_leaves_the_spray_where_it_was(): void
    {
        $dough = ProductionRecorder::dough(10, $this->baker->id);
        $chane = ProductionRecorder::chane($dough, $this->baker->id, 400.0, 0.0, 5.0, 470);

        $dough->update(['bag_count' => 20]);

        $this->assertSame(5.0, $this->movedBy($chane, InventoryItem::FLOUR));
        $this->assertSame(800.0, $this->movedBy($dough->refresh(), InventoryItem::FLOUR));
        $this->assertSame(3195.0, $this->flour());
    }

    public function test_the_chane_entry_is_not_touched_by_an_unrelated_edit(): void
    {
        $dough = ProductionRecorder::dough(10, $this->baker->id);
        $chane = ProductionRecorder::chane($dough, $this->baker->id, 400.0, 0.0, 5.0, 470);
        $before = $this->flour();

        $chane->update(['status' => 'sold']);

        $this->assertSame($before, $this->flour());
        $this->assertCount(1, InventoryMovement::where('source_type', ChaneEntry::class)
            ->where('source_id', $chane->id)->get());
    }
}
