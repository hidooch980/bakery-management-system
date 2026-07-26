<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BakeryShare;
use App\Models\Expense;
use App\Models\Income;
use App\Models\InventoryItem;
use App\Models\ShareSettlement;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Ledger;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomeAndSharesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Money::forgetCache();

        Bakery::first()->update(['currency' => 'toman', 'flour_bag_weight_kg' => 40]);
        Money::forgetCache();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    // ------------------------------------------------- miscellaneous income

    public function test_admin_records_a_miscellaneous_income(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/incomes', [
                'category' => 'rent',
                'title' => 'اجاره انبار',
                'amount' => 2_000_000,
                'received_on' => '1405/05/03',
            ])
            ->assertCreated()
            ->assertJsonPath('data.category_label', 'اجاره')
            ->assertJsonPath('data.received_on_display', '1405/05/03');

        $this->assertDatabaseHas('incomes', ['amount' => 2000000.00]);
    }

    public function test_income_rejects_an_unknown_category(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/incomes', [
                'category' => 'lottery',
                'title' => 'x',
                'amount' => 1,
            ])
            ->assertStatus(422);
    }

    public function test_income_amounts_are_stored_as_toman(): void
    {
        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/incomes', [
                'category' => 'other',
                'title' => 'متفرقه',
                'amount' => 1_000_000,
            ])->assertCreated();

        // A million Rial is a hundred thousand Toman.
        $this->assertEquals(100_000.0, (float) Income::first()->amount);
    }

    public function test_a_seller_cannot_record_income(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('seller');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/incomes', [
                'category' => 'other',
                'title' => 'x',
                'amount' => 1,
            ])
            ->assertForbidden();
    }

    // ---------------------------------------------------------- the ledger

    public function test_profit_counts_bread_flour_and_other_income(): void
    {
        [$from, $to] = Jalali::currentMonthRange();

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');

        $this->makeBreadSale(1_000_000);
        $this->makeFlourSale(100, 5_000);   // 500,000
        Income::create([
            'category' => 'rent',
            'title' => 'اجاره',
            'amount' => 300_000,
            'received_on' => now(),
        ]);
        Expense::create([
            'category' => 'fuel',
            'title' => 'سوخت',
            'amount' => 200_000,
            'spent_on' => now(),
        ]);

        $this->assertEquals(1_800_000.0, Ledger::totalIncome($from, $to));
        $this->assertEquals(200_000.0, Ledger::totalExpenses($from, $to));
        $this->assertEquals(1_600_000.0, Ledger::profit($from, $to));
    }

    public function test_the_financial_report_breaks_income_down_by_source(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');

        $this->makeBreadSale(1_000_000);
        $this->makeFlourSale(100, 5_000);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/reports/financial')
            ->assertOk()
            ->assertJsonPath('data.income.bread', 1000000)
            ->assertJsonPath('data.income.flour', 500000)
            ->assertJsonPath('data.income.total', 1500000);
    }

    // --------------------------------------------------------- partner dang

    public function test_share_fraction_is_derived_from_the_total_dang(): void
    {
        $a = BakeryShare::create(['name' => 'شریک اول', 'dang' => 4]);
        $b = BakeryShare::create(['name' => 'شریک دوم', 'dang' => 2]);

        $this->assertEquals(6.0, BakeryShare::totalDang());
        $this->assertEqualsWithDelta(0.6667, $a->share_fraction, 0.001);
        $this->assertEqualsWithDelta(0.3333, $b->fresh()->share_fraction, 0.001);
    }

    public function test_adding_a_partner_rebalances_everyone(): void
    {
        $a = BakeryShare::create(['name' => 'الف', 'dang' => 3]);
        BakeryShare::create(['name' => 'ب', 'dang' => 3]);

        $this->assertEquals(50.0, $a->fresh()->share_percent);

        // A third partner joins with two dang; the whole becomes eight.
        BakeryShare::create(['name' => 'ج', 'dang' => 2]);

        $this->assertEquals(37.5, $a->fresh()->share_percent);
    }

    public function test_an_inactive_partner_is_left_out_of_the_split(): void
    {
        BakeryShare::create(['name' => 'فعال', 'dang' => 3]);
        BakeryShare::create(['name' => 'غیرفعال', 'dang' => 3, 'is_active' => false]);

        $this->assertEquals(3.0, BakeryShare::totalDang());
    }

    public function test_profit_is_split_by_dang(): void
    {
        [$from, $to] = Jalali::currentMonthRange();

        $this->makeBreadSale(6_000_000);

        BakeryShare::create(['name' => 'الف', 'dang' => 4]);
        BakeryShare::create(['name' => 'ب', 'dang' => 2]);

        $split = BakeryShare::splitFor($from, $to);

        $this->assertEquals(6_000_000.0, $split['profit']);
        $this->assertEquals(4_000_000.0, $split['holders'][0]['amount']);
        $this->assertEquals(2_000_000.0, $split['holders'][1]['amount']);
    }

    public function test_the_split_endpoint_reports_each_partners_cut(): void
    {
        $this->makeBreadSale(3_000_000);

        BakeryShare::create(['name' => 'الف', 'dang' => 1]);
        BakeryShare::create(['name' => 'ب', 'dang' => 2]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/shares/split')
            ->assertOk()
            ->assertJsonPath('data.total_dang', 3)
            // Sorted by dang, so the two-dang holder comes first.
            ->assertJsonPath('data.holders.0.amount', 2000000)
            ->assertJsonPath('data.holders.1.amount', 1000000);
    }

    public function test_a_loss_is_shared_too(): void
    {
        [$from, $to] = Jalali::currentMonthRange();

        Expense::create([
            'category' => 'rent',
            'title' => 'اجاره',
            'amount' => 900_000,
            'spent_on' => now(),
        ]);

        BakeryShare::create(['name' => 'الف', 'dang' => 2]);
        BakeryShare::create(['name' => 'ب', 'dang' => 1]);

        $split = BakeryShare::splitFor($from, $to);

        $this->assertEquals(-900_000.0, $split['profit']);
        $this->assertEquals(-600_000.0, $split['holders'][0]['amount']);
    }

    public function test_the_cuts_always_add_back_up_to_the_profit(): void
    {
        [$from, $to] = Jalali::currentMonthRange();

        // An amount that does not divide evenly by three.
        $this->makeBreadSale(1_000_000.01);

        BakeryShare::create(['name' => 'الف', 'dang' => 1]);
        BakeryShare::create(['name' => 'ب', 'dang' => 1]);
        BakeryShare::create(['name' => 'ج', 'dang' => 1]);

        $split = BakeryShare::splitFor($from, $to);

        $this->assertEqualsWithDelta(
            $split['profit'],
            array_sum(array_column($split['holders'], 'amount')),
            0.001
        );
    }

    public function test_settling_a_partner_records_the_payout(): void
    {
        $this->makeBreadSale(6_000_000);

        $share = BakeryShare::create(['name' => 'الف', 'dang' => 6]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/shares/{$share->id}/settle", [])
            ->assertCreated();

        $this->assertEquals(6_000_000.0, (float) ShareSettlement::first()->amount);
    }

    public function test_a_settled_amount_is_frozen_against_later_bookkeeping(): void
    {
        [$from, $to] = Jalali::currentMonthRange();

        $this->makeBreadSale(6_000_000);

        $share = BakeryShare::create(['name' => 'الف', 'dang' => 6]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/shares/{$share->id}/settle", [])
            ->assertCreated();

        // A cost recorded afterwards lowers the profit, but must not rewrite
        // the payout that was already agreed and paid.
        Expense::create([
            'category' => 'fuel',
            'title' => 'سوخت',
            'amount' => 2_000_000,
            'spent_on' => now(),
        ]);

        $this->assertEquals(6_000_000.0, (float) ShareSettlement::first()->amount);
        $this->assertEquals(6_000_000.0, $share->fresh()->settledFor($from, $to));
        // The live entitlement did drop, so the partner now owes money back.
        $this->assertEquals(4_000_000.0, $share->fresh()->profitShare($from, $to));
    }

    public function test_the_split_reports_what_is_still_owed(): void
    {
        [$from, $to] = Jalali::currentMonthRange();

        $this->makeBreadSale(6_000_000);

        $share = BakeryShare::create(['name' => 'الف', 'dang' => 6]);

        ShareSettlement::create([
            'bakery_share_id' => $share->id,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'amount' => 2_000_000,
            'paid_on' => now(),
        ]);

        $split = BakeryShare::splitFor($from, $to);

        $this->assertEquals(2_000_000.0, $split['holders'][0]['paid']);
        $this->assertEquals(4_000_000.0, $split['holders'][0]['remaining']);
    }

    public function test_an_unpaid_settlement_does_not_count_as_paid(): void
    {
        [$from, $to] = Jalali::currentMonthRange();

        $share = BakeryShare::create(['name' => 'الف', 'dang' => 6]);

        ShareSettlement::create([
            'bakery_share_id' => $share->id,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'amount' => 2_000_000,
            'paid_on' => null,
        ]);

        $this->assertEquals(0.0, $share->settledFor($from, $to));
    }

    public function test_a_seller_cannot_see_the_profit_split(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('seller');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/shares/split')
            ->assertForbidden();
    }

    // ------------------------------------------------------------- helpers

    private function makeBreadSale(float $amount): void
    {
        $user = User::factory()->create();

        $dough = \App\Models\DoughEntry::create(['user_id' => $user->id, 'bag_count' => 1]);
        $chane = \App\Models\ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $user->id,
            'chane_count' => 10,
            'normal_weight_kg' => 10,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
        ]);

        \App\Models\Sale::create([
            'chane_entry_id' => $chane->id,
            'user_id' => $user->id,
            'payment_type' => 'cash',
            'amount' => $amount,
        ]);
    }

    private function makeFlourSale(float $kg, float $pricePerKg): void
    {
        \App\Models\FlourSale::create([
            'user_id' => User::factory()->create()->id,
            'unit' => 'kg',
            'quantity' => $kg,
            'unit_price' => $pricePerKg,
            'payment_type' => 'cash',
            'sold_on' => now(),
        ]);
    }
}
