<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports;
use App\Models\Bakery;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\ProductionRecorder;
use App\Support\ReportSeries;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «آرد کجا رفت» — the owner's question, answered over one range.
 *
 * The shop could already see how much was baked each day. What it could
 * not see was that of a month's sacks, four in five were baked, one in
 * seven went to a partner bakery and never came back, and a handful was
 * dusted on the bench — because production, lending, flour sales and
 * corrections each live on their own screen and only meet in the
 * warehouse ledger.
 */
class WhereTheFlourWentTest extends TestCase
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

        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 200, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 100, 'purchase');
    }

    /** @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon} */
    private function today(): array
    {
        return [now()->copy()->startOfDay(), now()->copy()->endOfDay()];
    }

    private function partner(): Customer
    {
        return Customer::create([
            'name' => 'نانوایی کنت',
            'type' => Customer::PARTNER_TYPE,
            'is_active' => true,
        ]);
    }

    public function test_it_names_every_destination_the_flour_took(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 4000, 'purchase');

        $dough = ProductionRecorder::dough(10, $this->baker->id);
        ProductionRecorder::chane($dough, $this->baker->id, 400.0, 0.0, 5.0, 470);

        ConsignmentFlour::create([
            'customer_id' => $this->partner()->id,
            'direction' => 'lent',
            'bags' => 2,
            'occurred_on' => today(),
        ]);

        [$from, $to] = $this->today();
        $journey = ReportSeries::flourJourney($from, $to);

        $out = collect($journey['out'])->keyBy('reason');

        $this->assertSame(400.0, $out['production']['kg']);
        $this->assertSame(5.0, $out['spray']['kg']);
        $this->assertSame(80.0, $out['consignment_out']['kg']);
        $this->assertSame(485.0, $journey['out_kg']);

        // Sacks lead, because that is what the shop counts.
        $this->assertSame(10.0, $out['production']['bags']);
        $this->assertSame(2.0, $out['consignment_out']['bags']);
    }

    public function test_the_shares_say_which_destination_took_most(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 4000, 'purchase');
        ProductionRecorder::dough(10, $this->baker->id);

        ConsignmentFlour::create([
            'customer_id' => $this->partner()->id,
            'direction' => 'lent',
            'bags' => 2,
            'occurred_on' => today(),
        ]);

        [$from, $to] = $this->today();
        $journey = ReportSeries::flourJourney($from, $to);

        // Biggest first: the answer to «where did it go» is the top line.
        $this->assertSame('production', $journey['out'][0]['reason']);
        $this->assertSame(83.3, $journey['out'][0]['share']);
        $this->assertSame(16.7, $journey['out'][1]['share']);
    }

    /**
     * A batch deleted the next day is not a delivery of flour. It is a
     * bake that did not happen, and a report that files it under «آمد»
     * tells the owner sacks turned up that nobody bought.
     */
    public function test_a_cancelled_bake_reduces_the_bake_rather_than_arriving_as_flour(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 4000, 'purchase');

        ProductionRecorder::dough(10, $this->baker->id);
        ProductionRecorder::dough(4, $this->baker->id)->delete();

        [$from, $to] = $this->today();
        $journey = ReportSeries::flourJourney($from, $to);

        $out = collect($journey['out'])->keyBy('reason');
        $in = collect($journey['in'])->keyBy('reason');

        $this->assertSame(400.0, $out['production']['kg'], 'the cancelled batch nets off the bake');
        $this->assertFalse($in->has('production_reversal'), 'and does not appear as flour arriving');
        $this->assertSame(4000.0, $journey['in_kg']);
    }

    /**
     * Flour lent and then handed back is a round trip, not a purchase.
     */
    public function test_a_settled_lending_nets_off_the_lending(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 4000, 'purchase');

        $lending = ConsignmentFlour::create([
            'customer_id' => $this->partner()->id,
            'direction' => 'lent',
            'bags' => 5,
            'occurred_on' => today(),
        ]);

        $lending->update(['settled_on' => today()]);

        [$from, $to] = $this->today();
        $journey = ReportSeries::flourJourney($from, $to);

        $this->assertSame([], $journey['out'], 'lent and returned leaves nothing out');
        $this->assertSame(4000.0, $journey['in_kg']);
    }

    /**
     * The identity that makes this a check and not just a list. It cannot
     * fail by arithmetic — every figure comes off one ledger — so the day
     * it does fail, something wrote a movement the report cannot place.
     */
    public function test_the_opening_and_closing_balances_frame_the_movement(): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', 1000, 'purchase', null, null, null);

        // Yesterday's stock, so the range opens on a balance rather than on
        // nothing. Straight to the table, because `created_at` is not
        // fillable and `update()` drops it without a word — which is how
        // this test first passed a nought off as a thousand.
        DB::table('inventory_movements')
            ->where('id', $flour->movements()->latest('id')->first()->id)
            ->update(['created_at' => now()->subDay()]);

        $flour->move('in', 4000, 'purchase');
        ProductionRecorder::dough(10, $this->baker->id);

        [$from, $to] = $this->today();
        $journey = ReportSeries::flourJourney($from, $to);

        $this->assertSame(1000.0, $journey['opening_kg']);
        $this->assertSame(4000.0, $journey['in_kg']);
        $this->assertSame(400.0, $journey['out_kg']);
        $this->assertSame(4600.0, $journey['closing_kg']);
        $this->assertTrue($journey['balances']);

        // And the closing figure is the store's own balance, not a second
        // opinion about it.
        $this->assertSame($flour->fresh()->balance, $journey['closing_kg']);
    }

    /**
     * The page actually renders with the section on it. A figure computed
     * correctly and a template that throws are the same thing to the owner:
     * a reports page that will not open.
     */
    public function test_the_reports_page_renders_the_flour_section(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 4000, 'purchase');
        ProductionRecorder::dough(10, $this->baker->id);

        Livewire::test(Reports::class)
            ->assertOk()
            ->assertSee('آرد کجا رفت')
            ->assertSee('مصرف در تولید');
    }

    public function test_a_range_with_no_movement_says_so_without_dividing_by_nothing(): void
    {
        [$from, $to] = $this->today();
        $journey = ReportSeries::flourJourney($from, $to);

        $this->assertSame([], $journey['out']);
        $this->assertSame([], $journey['in']);
        $this->assertSame(0.0, $journey['out_kg']);
        $this->assertTrue($journey['balances']);
    }
}
