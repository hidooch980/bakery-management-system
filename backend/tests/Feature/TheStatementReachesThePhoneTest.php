<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «سود و زیان», on the phone.
 *
 * The panel has had the statement since it was written. The phone had the
 * halves — income on one screen, expenses on another, profit in a widget —
 * and never the sum.
 *
 * On 2026-08-16 the dashboard and the report disagreed about profit by
 * 164,640,000 Rial because flour was counted both as cost of goods and as
 * an expense. Two screens each showing half a sum is how that survived.
 * So what is tested here is not that the endpoint answers, but that its
 * figures are the ledger's own: a third screen computing its own profit
 * would be that bug being built again.
 */
class TheStatementReachesThePhoneTest extends TestCase
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
            'flour_purchase_price_per_kg' => 20_000,
        ]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function statement(): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/reports/profit-and-loss')
            ->assertOk()
            ->json('data');
    }

    public function test_a_quiet_period_answers_with_zeroes_rather_than_failing(): void
    {
        $data = $this->statement();

        $this->assertEquals(0, $data['income_total']);
        $this->assertEquals(0, $data['expense_total']);
        $this->assertEquals(0, $data['profit']);
    }

    public function test_the_three_cost_lines_are_the_ones_the_owner_thinks_in(): void
    {
        $data = $this->statement();

        $this->assertCount(3, $data['costs']);
        $this->assertSame('خرید آرد', $data['costs'][0]['label']);
        $this->assertSame('حقوق پرداخت‌شده', $data['costs'][1]['label']);
        $this->assertSame('سایر هزینه‌ها', $data['costs'][2]['label']);
    }

    public function test_the_statement_adds_up(): void
    {
        // The arithmetic is the point: income less everything paid out is
        // the profit on the same page, so the two cannot be read apart.
        Expense::create([
            'category' => 'other',
            'amount' => 500_000,
            'spent_on' => now()->toDateString(),
            'title' => 'تعمیر',
            'user_id' => $this->admin->id,
        ]);

        $data = $this->statement();

        $costs = array_sum(array_column($data['costs'], 'amount'));

        $this->assertEquals($data['expense_total'], $costs);
        $this->assertEquals(
            $data['income_total'] - $data['expense_total'],
            $data['profit'],
        );
    }

    public function test_flour_is_not_counted_twice(): void
    {
        // This is the 164,640,000 Rial bug. Flour bought is a cost the day
        // the money leaves; flour baked is cost of goods. Both are here,
        // and the headline uses one of them.
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', 100, 'purchase', $this->admin->id);
        $flour->move('out', 80, 'production', $this->admin->id);

        $data = $this->statement();

        // Cost of goods is beside the profit, not inside it.
        $this->assertArrayHasKey('cogs', $data);
        $this->assertArrayHasKey('gross_profit', $data);
        $this->assertEquals(
            $data['income_total'] - $data['expense_total'],
            $data['profit'],
            'سود سرخط باید فقط پولِ خارج‌شده را کم کند.',
        );
    }

    public function test_every_figure_is_formatted_for_the_screen_too(): void
    {
        // The phone prints these; a raw number in Rial is unreadable at a
        // glance and the app does not know the shop's currency.
        $data = $this->statement();

        foreach (['income_total', 'expense_total', 'profit', 'cogs', 'gross_profit'] as $key) {
            $this->assertArrayHasKey($key.'_formatted', $data, $key);
        }

        foreach ($data['costs'] as $row) {
            $this->assertArrayHasKey('amount_formatted', $row);
        }
    }

    public function test_the_period_is_named_in_jalali(): void
    {
        // A statement whose dates a person cannot read is a statement they
        // cannot check.
        $data = $this->statement();

        $this->assertArrayHasKey('from_jalali', $data);
        $this->assertArrayHasKey('to_jalali', $data);
    }

    public function test_it_is_behind_the_finance_permission(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/reports/profit-and-loss')
            ->assertForbidden();
    }
}
