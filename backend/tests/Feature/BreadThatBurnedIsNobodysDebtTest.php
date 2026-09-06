<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\Sale;
use App\Models\User;
use App\Support\IssueScanner;
use App\Support\Money;
use App\Support\SellerSettlement;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A batch that came out burnt had nowhere honest to go.
 *
 * Closing it, the seller could take it as **کسری** — which lands on their
 * account, so they pay for the oven out of their own wages — or call it
 * **خیرات**, which is untrue and spoils the giving figure as well.
 *
 * «ضایعات» is the third answer: owed by nobody, but named as a loss
 * rather than a gift, because to the owner those are opposite facts.
 *
 * The tests that matter here are the fairness one — it must never reach
 * the seller's account — and the watch, because a category that costs
 * nobody is the obvious place to bury a theft.
 */
class BreadThatBurnedIsNobodysDebtTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'currency' => 'toman',
            'flour_bag_weight_kg' => 40,
            'bread_price' => 5000,
        ]);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 100_000, 'purchase');
    }

    private function batch(int $loaves): ChaneEntry
    {
        $dough = DoughEntry::create([
            'user_id' => $this->seller->id,
            'bag_count' => 1,
            'status' => 'shaped',
        ]);

        return ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => $loaves,
            'normal_weight_kg' => $loaves * 0.85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
        ]);
    }

    /** The whole point: the oven's mistake is not the seller's debt. */
    public function test_burnt_bread_never_lands_on_the_sellers_account(): void
    {
        $chane = $this->batch(100);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chane->id,
                'payments' => [
                    ['payment_type' => 'cash', 'bread_count' => 80, 'amount' => 400_000],
                    ['payment_type' => 'waste', 'bread_count' => 20],
                ],
            ])
            ->assertCreated();

        $owed = SellerSettlement::outstandingFor($this->seller);

        // The eighty sold are theirs to hand over. The twenty burnt are
        // nobody's.
        $this->assertSame(0, $owed['shortfall_loaves']);

        $waste = Sale::where('payment_type', 'waste')->sole();

        $this->assertNull($waste->shortfall_count);
        $this->assertNull($waste->amount_difference);
    }

    /**
     * The batch closes. Before this existed, twenty loaves unaccounted
     * for became an automatic shortfall — the backstop doing exactly what
     * it should, to somebody who had done nothing wrong.
     */
    public function test_waste_closes_the_batch_rather_than_leaving_a_shortfall(): void
    {
        $chane = $this->batch(100);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chane->id,
                'payments' => [
                    ['payment_type' => 'cash', 'bread_count' => 80, 'amount' => 400_000],
                    ['payment_type' => 'waste', 'bread_count' => 20],
                ],
            ])
            ->assertCreated();

        $this->assertSame(
            0,
            (int) Sale::where('chane_entry_id', $chane->id)->sum('shortfall_count'),
        );
    }

    /** Waste is not takings, and must not read as a giving figure either. */
    public function test_waste_earns_nothing_and_is_not_charity(): void
    {
        $chane = $this->batch(100);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chane->id,
                'payments' => [
                    ['payment_type' => 'waste', 'bread_count' => 100],
                ],
            ])
            ->assertCreated();

        $waste = Sale::sole();

        $this->assertNull($waste->amount);
        $this->assertSame(0, Sale::where('payment_type', 'charity')->count());
    }

    // ------------------------------------------------- and it is watched

    /** Sales spread over the fortnight, [$wasted] of them written off. */
    private function fortnight(int $sold, int $wasted): void
    {
        $chane = $this->batch($sold + $wasted);

        Sale::create([
            'user_id' => $this->seller->id,
            'chane_entry_id' => $chane->id,
            'payment_type' => 'cash',
            'bread_count' => $sold,
            'amount' => $sold * 5000,
        ]);

        if ($wasted > 0) {
            Sale::create([
                'user_id' => $this->seller->id,
                'chane_entry_id' => $chane->id,
                'payment_type' => 'waste',
                'bread_count' => $wasted,
            ]);
        }
    }

    private function wasteIssue(): ?object
    {
        return (new IssueScanner)->scan()->firstWhere('key', 'waste-high');
    }

    public function test_a_fortnight_losing_too_much_is_said_out_loud(): void
    {
        $this->fortnight(sold: 900, wasted: 100);

        $issue = $this->wasteIssue();

        $this->assertNotNull($issue);
        $this->assertStringContainsString('۱۰٪', $issue->detail);
        // Two very different things look identical from here, and the
        // issue does not pretend to tell them apart.
        $this->assertStringContainsString('ضایعات نبوده', $issue->cause);
    }

    public function test_an_ordinary_fortnight_says_nothing(): void
    {
        $this->fortnight(sold: 990, wasted: 10);

        $this->assertNull($this->wasteIssue());
    }

    /**
     * A percentage of almost nothing swings on a single loaf. A quiet
     * fortnight must not raise an alarm about two burnt loaves.
     */
    public function test_too_little_baking_to_take_a_percentage_of_says_nothing(): void
    {
        $this->fortnight(sold: 20, wasted: 10);

        $this->assertNull($this->wasteIssue());
    }
}
