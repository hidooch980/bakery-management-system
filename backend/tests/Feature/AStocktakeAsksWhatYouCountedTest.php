<?php

namespace Tests\Feature;

use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems;
use App\Models\Bakery;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Recording a physical count by saying what was counted.
 *
 * The movement form takes a *difference*, in a direction the counter has
 * to work out: to record «I counted 71 sacks» the owner had to read the
 * ledger off another line, subtract by hand, and decide whether the
 * result was an in or an out. Two ways to be silently wrong — the sign
 * backwards, which doubles the gap, or the counted total typed where the
 * difference belonged, which on 2026-09-03 would have added 71 sacks to
 * a store holding 65.
 *
 * The arithmetic is the machine's now. What a person types is the one
 * number they actually know.
 */
class AStocktakeAsksWhatYouCountedTest extends TestCase
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
    }

    private function flour(): InventoryItem
    {
        return InventoryItem::ofKey(InventoryItem::FLOUR);
    }

    private function countStock(InventoryItem $item, float $counted, ?string $note = null): void
    {
        Livewire::test(ListInventoryItems::class)
            ->callTableAction('stocktake', $item, ['counted' => $counted, 'note' => $note]);
    }

    /**
     * The real shape of 2026-09-03: 65.15 sacks on the books, 71 in the
     * store. The person types 71.
     */
    public function test_counting_more_than_the_books_brings_the_difference_in(): void
    {
        $flour = $this->flour();
        $flour->move('in', 2606, 'purchase');

        $this->countStock($flour, 71);

        // 71 sacks at 40 kg. Not 2606 + 2840.
        $this->assertEqualsWithDelta(2840, $flour->fresh()->balance, 0.001);

        $movement = InventoryMovement::where('reason', 'stocktake')->firstOrFail();
        $this->assertSame('in', $movement->direction);
        $this->assertEqualsWithDelta(234, (float) $movement->quantity, 0.001);
    }

    public function test_counting_less_than_the_books_takes_the_difference_out(): void
    {
        $flour = $this->flour();
        $flour->move('in', 2840, 'purchase');

        $this->countStock($flour, 60);

        $this->assertEqualsWithDelta(2400, $flour->fresh()->balance, 0.001);

        $movement = InventoryMovement::where('reason', 'stocktake')->firstOrFail();
        $this->assertSame('out', $movement->direction);
        $this->assertEqualsWithDelta(440, (float) $movement->quantity, 0.001);
    }

    /**
     * The mistake the old form invited. Typing the counted total where a
     * difference belonged added a whole store to itself.
     */
    public function test_the_counted_total_is_never_added_to_what_is_already_there(): void
    {
        $flour = $this->flour();
        $flour->move('in', 2606, 'purchase');

        $this->countStock($flour, 71);

        // 2606 + 2840 = 5446 is the number this test exists to refuse.
        $this->assertEqualsWithDelta(2840, $flour->fresh()->balance, 0.001);
    }

    public function test_a_count_that_agrees_writes_no_movement_at_all(): void
    {
        $flour = $this->flour();
        $flour->move('in', 2840, 'purchase');

        $this->countStock($flour, 71);

        // A line that moved nothing is a line to read past for ever.
        $this->assertSame(0, InventoryMovement::where('reason', 'stocktake')->count());
        $this->assertEqualsWithDelta(2840, $flour->fresh()->balance, 0.001);
    }

    /**
     * The one stocktake on file with no note — 4.68 kg of yeast on
     * 1405/06/03 — says nothing about what was counted or what the books
     * held, and so cannot be argued with by anybody. A line that says
     * what it is can be.
     */
    public function test_the_movement_writes_down_what_was_counted_and_what_the_books_held(): void
    {
        $flour = $this->flour();
        $flour->move('in', 2606, 'purchase');

        $this->countStock($flour, 71);

        $note = InventoryMovement::where('reason', 'stocktake')->firstOrFail()->note;

        $this->assertStringContainsString('71', $note);      // counted
        $this->assertStringContainsString('65.15', $note);   // the books
        $this->assertStringContainsString('5.85', $note);    // the gap
    }

    public function test_the_counters_own_words_are_kept_beside_the_figures(): void
    {
        $flour = $this->flour();
        $flour->move('in', 2606, 'purchase');

        $this->countStock($flour, 71, 'دو کیسه پاره در گوشهٔ انبار پیدا شد');

        $this->assertStringContainsString(
            'دو کیسه پاره در گوشهٔ انبار پیدا شد',
            InventoryMovement::where('reason', 'stocktake')->firstOrFail()->note
        );
    }

    /**
     * Salt and yeast arrive in no fixed sack, so the number a person has
     * in mind is a weight and the form must ask for one.
     */
    public function test_an_item_with_no_sack_is_counted_by_weight(): void
    {
        // Salt and yeast arrive in no fixed sack in this shop — the live
        // records carry no bag weight and the panel shows them in
        // kilograms. Set here rather than assumed, so the test states the
        // condition it is about.
        $salt = InventoryItem::ofKey(InventoryItem::SALT);
        $salt->forceFill(['bag_weight_kg' => 0])->save();

        $salt->move('in', 100, 'purchase');

        $this->countStock($salt, 88.64);

        $this->assertEqualsWithDelta(88.64, $salt->fresh()->balance, 0.001);
        $this->assertSame('out', InventoryMovement::where('reason', 'stocktake')->firstOrFail()->direction);
    }

    /**
     * A shelf can be empty, and refusing to record that would leave the
     * books claiming stock nobody can find.
     */
    public function test_counting_nothing_empties_the_shelf(): void
    {
        $flour = $this->flour();
        $flour->move('in', 2840, 'purchase');

        $this->countStock($flour, 0);

        $this->assertEqualsWithDelta(0, $flour->fresh()->balance, 0.001);
    }

    public function test_a_stocktake_never_drives_the_balance_below_nothing(): void
    {
        $flour = $this->flour();
        $flour->move('in', 2840, 'purchase');

        $this->countStock($flour, 10);

        // The count is the floor: an out can only ever be the gap down to
        // what was counted, so 400 kg is where it lands and not below.
        $this->assertEqualsWithDelta(400, $flour->fresh()->balance, 0.001);
    }

    /**
     * The dropdown that accepted either a total or a difference is the
     * one place the two could be confused.
     */
    public function test_the_movement_form_no_longer_accepts_a_stocktake_reason(): void
    {
        $this->assertArrayHasKey(
            'stocktake',
            InventoryMovement::REASONS,
            'علت باید همچنان برای نمایش در دفتر بماند.'
        );

        $flour = $this->flour();
        $flour->move('in', 2840, 'purchase');

        Livewire::test(ListInventoryItems::class)
            ->callTableAction('recordStock', $flour, [
                'direction' => 'in',
                'bags' => 5,
                'reason' => 'stocktake',
                'note' => null,
            ])
            ->assertHasTableActionErrors(['reason']);

        // And nothing was written on the way to being refused.
        $this->assertEqualsWithDelta(2840, $flour->fresh()->balance, 0.001);
    }

    public function test_a_recorded_stocktake_still_reads_back_with_its_persian_name(): void
    {
        $flour = $this->flour();
        $flour->move('in', 2606, 'purchase');

        $this->countStock($flour, 71);

        // The reason stays in REASONS so the ledger can name it, even
        // though nothing may choose it by hand any more.
        $this->assertSame(
            'شمارش انبار',
            InventoryMovement::where('reason', 'stocktake')->firstOrFail()->reason_label
        );
    }

    public function test_the_action_is_on_the_page(): void
    {
        $this->get(InventoryItemResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('ثبت شمارش انبار');
    }
}
