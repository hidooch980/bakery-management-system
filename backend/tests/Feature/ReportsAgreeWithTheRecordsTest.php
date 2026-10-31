<?php

namespace Tests\Feature;

use App\Filament\Widgets\IncomeExpenseChart;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BaleReport;
use App\Support\Ledger;
use App\Support\ManagerAlert;
use App\Support\Money;
use App\Support\ProductionRecorder;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The report audit of 1405/08/09: every report checked against the raw
 * rows on a copy of the shop's own data. Each test is one thing that was
 * wrong, and stays here so it cannot come back.
 */
class ReportsAgreeWithTheRecordsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first()->update([
            'currency' => 'rial',
            'flour_bag_weight_kg' => 40,
            'flour_purchase_price_per_kg' => 1000,
            'water_ratio' => 0.7,
            'salt_ratio' => 0.016,
            'yeast_ratio' => 0.0025,
            'dough_loss_ratio' => 0,
            'proof_gain_ratio' => 0,
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
        ]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 4000, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 200, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 100, 'purchase');
    }

    private function range(): array
    {
        return [now()->startOfDay(), now()->endOfDay()];
    }

    private function sale(float $amount, int $bread): void
    {
        $dough = DoughEntry::create(['user_id' => $this->admin->id, 'bag_count' => 1, 'status' => 'processed']);
        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id, 'user_id' => $this->admin->id, 'chane_count' => $bread,
            'normal_weight_kg' => 1, 'nanino_weight_kg' => 0, 'spray_flour_kg' => 0, 'status' => 'sold',
        ]);
        Sale::create([
            'chane_entry_id' => $chane->id, 'user_id' => $this->admin->id, 'bread_count' => $bread,
            'payment_type' => 'cash', 'amount' => $amount, 'amount_difference' => 0,
        ]);
    }

    private function purchase(float $amount): void
    {
        Purchase::forceCreate([
            'user_id' => $this->admin->id,
            'supplier_id' => Supplier::firstOrCreate(['name' => 'آسیاب مرکزی'])->id,
            'purchased_on' => now()->toDateString(),
            'amount' => $amount,
            'paid_amount' => 0,
        ]);
    }

    public function test_a_deleted_batch_is_not_counted_as_flour_baked(): void
    {
        ProductionRecorder::dough(10, $this->admin->id);
        ProductionRecorder::dough(5, $this->admin->id)->delete();

        [$from, $to] = $this->range();

        $this->assertEqualsWithDelta(400.0, array_sum(Ledger::dailyNetOut(InventoryItem::ofKey('flour'), ['production'], $from, $to)), 0.001);
        // Costed as it is baked: 400 kg at 1,000, not 600 kg.
        $this->assertEqualsWithDelta(400_000.0, Ledger::flourConsumedCost($from, $to), 0.01);

        $series = $this->getJson('/api/v1/reports/consumption-series?granularity=day')->assertOk()->json('data');
        $this->assertEqualsWithDelta(400.0, $series['totals']['flour_used_kg'], 0.001);
        $this->assertEqualsWithDelta(10.0, $series['totals']['flour_production_bags'], 0.001);
    }

    public function test_dusting_flour_is_reported_apart_from_dough_flour_in_bags(): void
    {
        $dough = ProductionRecorder::dough(10, $this->admin->id);
        $chane = ProductionRecorder::chane($dough, $this->admin->id, 100, 0, 20, 100);
        // Corrected down: the 5 kg given back is not dusting flour.
        $chane->update(['spray_flour_kg' => 15]);

        [$from, $to] = $this->range();
        $split = Ledger::flourSplit($from, $to);
        $this->assertEqualsWithDelta(10.0, $split['dough_bags'], 0.001);
        $this->assertEqualsWithDelta(0.38, $split['spray_bags'], 0.001);
        $this->assertEqualsWithDelta(15.0, $split['spray_kg'], 0.001);

        $dashboard = $this->getJson('/api/v1/reports/dashboard')->assertOk()->json('data');
        $this->assertEqualsWithDelta(15.0, $dashboard['flour_today']['spray_kg'], 0.001);
        $this->assertEqualsWithDelta(400.0, $dashboard['flour_today']['dough_kg'], 0.001);

        $production = $this->getJson('/api/v1/reports/production')->assertOk()->json('data');
        $this->assertEqualsWithDelta(0.38, $production['flour']['spray_bags'], 0.001);
        $this->assertEqualsWithDelta(0.38, end($production['daily'])['spray_flour_bags'], 0.001);
        $this->assertEqualsWithDelta(10.0, end($production['daily'])['dough_flour_bags'], 0.001);

        $series = $this->getJson('/api/v1/reports/consumption-series?granularity=month')->assertOk()->json('data');
        $this->assertEqualsWithDelta(0.38, $series['totals']['flour_spray_bags'], 0.001);
        $this->assertEqualsWithDelta(0.38, $series['rows'][0]['flour_spray_bags'], 0.001);
    }

    public function test_a_correction_counts_on_the_day_of_the_bake_it_corrects(): void
    {
        Carbon::setTestNow(now()->subDays(3)->setTime(10, 0));
        $dough = ProductionRecorder::dough(10, $this->admin->id);
        $chane = ProductionRecorder::chane($dough, $this->admin->id, 100, 0, 20, 100);
        Carbon::setTestNow();

        $chane->update(['spray_flour_kg' => 5]);

        $bakeDay = [now()->subDays(3)->startOfDay(), now()->subDays(3)->endOfDay()];
        $this->assertEqualsWithDelta(5.0, Ledger::flourSplit(...$bakeDay)['spray_kg'], 0.001);
        $this->assertEqualsWithDelta(0.0, Ledger::flourSplit(...$this->range())['spray_kg'], 0.001);
    }

    public function test_flour_kneaded_reads_as_dough_flour_not_production(): void
    {
        ProductionRecorder::dough(2, $this->admin->id);

        $movement = InventoryMovement::query()->where('reason', 'production')
            ->where('inventory_item_id', InventoryItem::ofKey('flour')->id)->first();
        $this->assertSame('آرد خمیرگیری', $movement->reason_label);

        $salt = InventoryMovement::query()->where('reason', 'production')
            ->where('inventory_item_id', InventoryItem::ofKey('salt')->id)->first();
        $this->assertSame('مصرف در تولید', $salt->reason_label);

        $day = $this->getJson('/api/v1/flour-day')->json('data');
        if ($day !== null) {
            $this->assertContains('آرد خمیرگیری', collect($day['out'])->pluck('label')->all());
        }
    }

    public function test_the_phone_profit_counts_purchase_invoices_like_the_panel(): void
    {
        $this->sale(1_000_000, 100);
        Expense::forceCreate(['user_id' => $this->admin->id, 'title' => 'کرایه', 'category' => 'other', 'amount' => 100_000, 'spent_on' => now()->toDateString()]);
        $this->purchase(300_000);

        [$from, $to] = $this->range();
        $panel = Ledger::profit($from, $to);
        $this->assertEqualsWithDelta(600_000.0, $panel, 0.01);

        $financial = $this->getJson('/api/v1/reports/financial')->assertOk()->json('data');
        $this->assertEqualsWithDelta($panel, $financial['profit']['amount'], 0.01);
        $this->assertEqualsWithDelta(400_000.0, $financial['expenses']['total'], 0.01);
        $this->assertEqualsWithDelta(300_000.0, $financial['expenses']['purchases'], 0.01);

        $series = $this->getJson('/api/v1/reports/financial-series?granularity=day')->assertOk()->json('data');
        $this->assertEqualsWithDelta($panel, $series['totals']['profit'], 0.01);
        $this->assertEqualsWithDelta(300_000.0, end($series['rows'])['expense_purchases'], 0.01);
    }

    public function test_the_panel_income_chart_matches_the_phone_trend(): void
    {
        $this->sale(100_000, 10);
        $this->purchase(50_000);

        $chart = new IncomeExpenseChart;
        $data = (fn () => $this->getData())->call($chart);
        [$income, $expense] = Ledger::dailyTotals(now());

        $this->assertEqualsWithDelta(Money::convert($income), end($data['datasets'][0]['data']), 0.01);
        $this->assertEqualsWithDelta(Money::convert($expense), end($data['datasets'][1]['data']), 0.01);
        $this->assertEqualsWithDelta(Money::convert(50_000), end($data['datasets'][1]['data']), 0.01);
    }

    public function test_the_nightly_bale_report_prints_rial_and_both_flours(): void
    {
        $this->sale(700_000, 70);
        $dough = ProductionRecorder::dough(10, $this->admin->id);
        ProductionRecorder::chane($dough, $this->admin->id, 100, 0, 20, 100);

        $text = BaleReport::nightly(now());

        // 700,000 toman is 7,000,000 rial; the old script printed «700,000 ریال».
        $this->assertStringContainsString(Money::format(700_000), $text);
        $this->assertStringContainsString('7،000،000 ریال', $text);
        $this->assertStringContainsString('آرد خمیرگیری: 10', $text);
        $this->assertStringContainsString('آرد پاششی: 0.5', $text);
    }

    public function test_the_bale_command_sends_nothing_when_printing_and_waits_for_the_month_end(): void
    {
        ManagerAlert::fake();

        $this->artisan('reports:bale', ['kind' => 'nightly', '--print' => true])->assertSuccessful();
        $this->assertSame([], ManagerAlert::sent());

        $this->artisan('reports:bale', ['kind' => 'weekly'])->assertSuccessful();
        $this->assertCount(1, ManagerAlert::sent());

        $notMonthEnd = Carbon::parse('2026-10-25');
        $this->artisan('reports:bale', ['kind' => 'monthly', '--ref' => $notMonthEnd->toDateString()])->assertSuccessful();
        $this->assertCount(1, ManagerAlert::sent());
    }

    public function test_a_settled_then_deleted_partner_record_leaves_the_store_where_it_was(): void
    {
        $partner = Customer::create(['name' => 'نانوایی هیدوچ', 'type' => Customer::PARTNER_TYPE, 'is_active' => true]);
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $before = $flour->fresh()->balance;

        $record = ConsignmentFlour::create(['customer_id' => $partner->id, 'direction' => 'lent', 'bags' => 15, 'occurred_on' => today()]);
        $record->update(['settled_on' => today()->toDateString()]);
        $record->delete();

        // Record #17 on the shop's copy: lent 600 kg, settled, deleted, and
        // the store came out 600 kg up.
        $this->assertEqualsWithDelta($before, $flour->fresh()->balance, 0.001);
    }
}
