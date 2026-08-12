<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\Expense;
use App\Models\Income;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Ledger;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The figure the shop called profit was money in less money out, which
 * counts flour only on the day a purchase was recorded. Bake through a sack
 * bought last month and the bread looked pure profit. The statement here
 * costs the flour as it is consumed, so the bread and the flour it came
 * from sit in the same period.
 */
class ProfitAndLossTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'currency' => 'toman',
            // 20,000 Toman the kilo, so the arithmetic below stays readable.
            'flour_purchase_price_per_kg' => 20_000,
        ]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    /** Flour arriving, then flour baked, entered straight on the store. */
    private function bakeFlour(float $kg): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', $kg + 50, 'purchase', $this->admin->id);
        $flour->move('out', $kg, 'production', $this->admin->id);
    }

    public function test_flour_baked_is_costed_at_the_purchase_price(): void
    {
        $this->bakeFlour(80);

        // 80 kg at 20,000 the kilo.
        $this->assertEquals(
            1_600_000.0,
            Ledger::flourConsumedCost(now()->startOfDay(), now()->endOfDay()),
        );
    }

    public function test_spray_flour_counts_and_other_withdrawals_do_not(): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', 200, 'purchase', $this->admin->id);
        $flour->move('out', 80, 'production', $this->admin->id);
        $flour->move('out', 3, 'spray', $this->admin->id);
        // Sold on, not baked: its cost belongs to that sale.
        $flour->move('out', 50, 'sale', $this->admin->id);

        $this->assertEquals(
            (80 + 3) * 20_000.0,
            Ledger::flourConsumedCost(now()->startOfDay(), now()->endOfDay()),
        );
    }

    public function test_no_purchase_price_means_no_guessed_cost(): void
    {
        Bakery::first()->update(['flour_purchase_price_per_kg' => null]);
        $this->bakeFlour(80);

        // Zero and visibly zero, rather than a number invented from nothing.
        $this->assertEquals(
            0.0,
            Ledger::flourConsumedCost(now()->startOfDay(), now()->endOfDay()),
        );
    }

    public function test_the_statement_reads_gross_then_net(): void
    {
        $this->bakeFlour(80); // 1,600,000 of flour into bread

        Income::create([
            'user_id' => $this->admin->id,
            'category' => 'other',
            'title' => 'فروش روز',
            'amount' => 5_000_000,
            'received_on' => now(),
        ]);

        Expense::create([
            'user_id' => $this->admin->id,
            'category' => array_key_first(Expense::CATEGORIES),
            'title' => 'برق',
            'amount' => 400_000,
            'spent_on' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/reports/financial')
            ->assertOk();

        $pnl = $response->json('data.profit_and_loss');

        $this->assertEquals(5_000_000, $pnl['income']);
        $this->assertEquals(1_600_000, $pnl['cost_of_goods']);
        $this->assertEquals(3_400_000, $pnl['gross_profit']);
        $this->assertEquals(400_000, $pnl['operating_expenses']);
        $this->assertEquals(3_000_000, $pnl['net_profit']);
    }

    public function test_the_old_profit_figure_is_left_exactly_as_it_was(): void
    {
        // The partners' split is paid on it; a report must not quietly
        // change what they are owed.
        $this->bakeFlour(80);

        Income::create([
            'user_id' => $this->admin->id,
            'category' => 'other',
            'title' => 'فروش روز',
            'amount' => 5_000_000,
            'received_on' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/reports/financial')
            ->assertOk();

        // Cash profit ignores the flour, as it always has.
        $this->assertEquals(5_000_000, $response->json('data.profit.amount'));
    }
}
