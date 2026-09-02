<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use App\Support\SaleRecorder;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shortfall is about the batch, so correcting a sale has to move it.
 *
 * Batch #142 on 1405/06/07: four payment lines written together, then each
 * corrected by hand a few minutes later in the panel. The bread counts
 * went up by 33 loaves; the shortfall stayed at 66. The seller was
 * answering for twice what was missing — 3,300,000 rial that was not owed
 * — and nothing anywhere disagreed, because both numbers were on file and
 * only their sum gave it away.
 *
 * The fourth derived figure on this model to go stale behind an edit,
 * after consignment stock, a worker's bread debt and a flour sale's
 * weight. Same lesson each time: the edit path needs what the create path
 * has.
 */
class CorrectingASaleMovesTheShortfallTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['bread_price' => 10000, 'currency' => 'toman']);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');
    }

    private function batch(int $loaves): ChaneEntry
    {
        $dough = DoughEntry::create([
            'user_id' => $this->seller->id,
            'bag_count' => 10,
        ]);

        return ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => $loaves,
            'normal_weight_kg' => $loaves * 0.85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 5,
        ]);
    }

    /** The shortfall carried anywhere on the batch. */
    private function shortfallOn(ChaneEntry $batch): int
    {
        return (int) Sale::where('chane_entry_id', $batch->id)->sum('shortfall_count');
    }

    public function test_the_real_batch_that_charged_a_seller_twice(): void
    {
        // 1514 shaped, 1448 accounted for at the counter: 66 short.
        $batch = $this->batch(1514);

        SaleRecorder::record($batch, [
            ['payment_type' => 'cash', 'bread_count' => 13, 'amount' => 130000],
            ['payment_type' => 'card', 'bread_count' => 1400, 'amount' => 14000000],
            ['payment_type' => 'home', 'bread_count' => 26],
            ['payment_type' => 'charity', 'bread_count' => 9],
        ], $this->seller->id);

        $this->assertSame(66, $this->shortfallOn($batch), 'as first recorded');

        // Then the cash line is corrected upward, the way it was in the
        // panel: 13 loaves were really 46.
        $cash = Sale::where('chane_entry_id', $batch->id)->where('payment_type', 'cash')->first();
        $cash->update(['bread_count' => 46]);

        // 1514 − 1481 = 33. Not 66.
        $this->assertSame(33, $this->shortfallOn($batch));
    }

    public function test_correcting_a_sale_downward_grows_the_shortfall(): void
    {
        $batch = $this->batch(100);

        SaleRecorder::record($batch, [
            ['payment_type' => 'cash', 'bread_count' => 90, 'amount' => 900000],
        ], $this->seller->id);

        $this->assertSame(10, $this->shortfallOn($batch));

        Sale::where('chane_entry_id', $batch->id)->first()->update(['bread_count' => 70]);

        $this->assertSame(30, $this->shortfallOn($batch));
    }

    public function test_a_batch_corrected_to_full_has_no_shortfall_left(): void
    {
        $batch = $this->batch(100);

        SaleRecorder::record($batch, [
            ['payment_type' => 'cash', 'bread_count' => 80, 'amount' => 800000],
        ], $this->seller->id);

        $this->assertSame(20, $this->shortfallOn($batch));

        Sale::where('chane_entry_id', $batch->id)->first()->update(['bread_count' => 100]);

        // Nothing missing any more, and the figure has to go rather than
        // sit at its old value.
        $this->assertSame(0, $this->shortfallOn($batch));
        $this->assertNull(Sale::where('chane_entry_id', $batch->id)->first()->shortfall_count);
    }

    public function test_the_money_follows_the_count(): void
    {
        $batch = $this->batch(100);

        SaleRecorder::record($batch, [
            ['payment_type' => 'cash', 'bread_count' => 90, 'amount' => 900000],
        ], $this->seller->id);

        Sale::where('chane_entry_id', $batch->id)->first()->update(['bread_count' => 70]);

        // 30 loaves at the day's rate, not the old figure's worth.
        $this->assertEquals(30 * 10000, (float) Sale::where('chane_entry_id', $batch->id)->first()->shortfall_amount);
    }

    public function test_a_settled_shortfall_is_not_rewritten(): void
    {
        $batch = $this->batch(100);

        SaleRecorder::record($batch, [
            ['payment_type' => 'cash', 'bread_count' => 90, 'amount' => 900000],
        ], $this->seller->id);

        $sale = Sale::where('chane_entry_id', $batch->id)->first();
        $sale->update(['shortfall_settled_on' => now()]);

        // Money that has already changed hands stands, whatever the
        // arithmetic says afterwards.
        $sale->update(['bread_count' => 70]);

        $this->assertSame(10, (int) $sale->fresh()->shortfall_count);
    }

    public function test_deleting_a_line_moves_the_shortfall_too(): void
    {
        $batch = $this->batch(100);

        SaleRecorder::record($batch, [
            ['payment_type' => 'cash', 'bread_count' => 60, 'amount' => 600000],
            ['payment_type' => 'card', 'bread_count' => 30, 'amount' => 300000],
        ], $this->seller->id);

        $this->assertSame(10, $this->shortfallOn($batch));

        Sale::where('chane_entry_id', $batch->id)->where('payment_type', 'card')->first()->delete();

        // Thirty loaves that were accounted for no longer are.
        $this->assertSame(40, $this->shortfallOn($batch));
    }

    public function test_a_settled_shortfall_is_not_charged_twice(): void
    {
        // The shape of batch #115, which the first run of the recompute
        // got wrong: 800 shaped, 742 sold, and 58 already settled on a
        // line further down. Walking the rows in order, the remainder was
        // written on the first line before the settled one was reached —
        // so the same 58 loaves were charged twice, 116 in total.
        $batch = $this->batch(800);

        SaleRecorder::record($batch, [
            ['payment_type' => 'card', 'bread_count' => 714, 'amount' => 7140000],
            ['payment_type' => 'home', 'bread_count' => 9],
            ['payment_type' => 'schools', 'bread_count' => 10],
            ['payment_type' => 'credit', 'bread_count' => 5],
            ['payment_type' => 'charity', 'bread_count' => 4],
        ], $this->seller->id);

        // The batch is 58 short; somebody answers for it.
        $carrier = Sale::where('chane_entry_id', $batch->id)
            ->whereNotNull('shortfall_count')->first();

        $this->assertSame(58, (int) $carrier->shortfall_count);
        $carrier->update(['shortfall_settled_on' => now()]);

        // Recomputing must now find nothing left to charge: those loaves
        // are missing and answered for at the same time.
        SaleRecorder::refreshBatchShortfall($batch->fresh());

        $this->assertSame(58, $this->shortfallOn($batch), 'not 116');
    }

    public function test_a_correction_after_a_settlement_only_moves_what_is_left(): void
    {
        $batch = $this->batch(100);

        SaleRecorder::record($batch, [
            ['payment_type' => 'cash', 'bread_count' => 80, 'amount' => 800000],
        ], $this->seller->id);

        $sale = Sale::where('chane_entry_id', $batch->id)->first();
        $sale->update(['shortfall_settled_on' => now()]);
        $this->assertSame(20, $this->shortfallOn($batch));

        // Ten of those loaves turn out to have been sold after all. The
        // settled twenty stands — that money changed hands — and nothing
        // new is added on top.
        $sale->update(['bread_count' => 90]);

        $this->assertSame(20, $this->shortfallOn($batch));
    }
}
