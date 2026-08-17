<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\Sale;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\PeriodBuckets;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Income, cost and consumption read a day, a week or a month at a time.
 */
class ReportSeriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Money::forgetCache();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function saleOn(string $jalaliDate, float $amount): void
    {
        $user = $this->admin();
        $dough = DoughEntry::create(['user_id' => $user->id, 'bag_count' => 1]);
        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $user->id,
            'chane_count' => 10,
            'normal_weight_kg' => 8.5,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
        ]);

        $sale = Sale::create([
            'chane_entry_id' => $chane->id,
            'user_id' => $user->id,
            'payment_type' => 'cash',
            'bread_count' => 10,
            'amount' => $amount,
        ]);

        DB::table('sales')->where('id', $sale->id)->update([
            'created_at' => Jalali::parse($jalaliDate)->setTime(10, 0),
        ]);
    }

    // ------------------------------------------------------- the buckets

    public function test_a_week_runs_saturday_to_friday(): void
    {
        // 1405/05/12 is a Monday, so the week it belongs to opened on 05/10.
        $buckets = PeriodBuckets::build(
            Jalali::parse('1405/05/08'),
            Jalali::parse('1405/05/12'),
            PeriodBuckets::WEEK
        );

        // The first bucket is cut short by the asked-for start; the next one
        // opens on the Saturday, which is where a week really begins.
        $this->assertCount(2, $buckets);
        $this->assertSame('1405/05/09', Jalali::toLatinDigits(Jalali::date($buckets[0]['to'])));
        $this->assertSame(Carbon::SATURDAY, $buckets[1]['from']->dayOfWeek);
        $this->assertSame('1405/05/10', Jalali::toLatinDigits(Jalali::date($buckets[1]['from'])));
    }

    public function test_a_month_is_the_jalali_one(): void
    {
        $buckets = PeriodBuckets::build(
            Jalali::parse('1405/05/01'),
            Jalali::parse('1405/06/15'),
            PeriodBuckets::MONTH
        );

        $this->assertCount(2, $buckets);
        // The bucket stops at the end of Mordad, not at the end of July.
        $this->assertSame('1405/05/31', Jalali::toLatinDigits(Jalali::date($buckets[0]['to'])));
    }

    public function test_a_range_starting_mid_bucket_reports_from_where_it_was_asked(): void
    {
        $buckets = PeriodBuckets::build(
            Jalali::parse('1405/05/10'),
            Jalali::parse('1405/05/20'),
            PeriodBuckets::MONTH
        );

        $this->assertSame('1405/05/10', Jalali::toLatinDigits(Jalali::date($buckets[0]['from'])));
        $this->assertSame('1405/05/20', Jalali::toLatinDigits(Jalali::date($buckets[0]['to'])));
    }

    public function test_an_unknown_granularity_falls_back_to_daily(): void
    {
        $this->assertSame(PeriodBuckets::DAY, PeriodBuckets::normalise('quarterly'));
        $this->assertSame(PeriodBuckets::DAY, PeriodBuckets::normalise(null));
    }

    // -------------------------------------------------- income and cost

    public function test_the_financial_series_reports_a_row_a_day(): void
    {
        $this->saleOn('1405/05/10', 500000);
        $this->saleOn('1405/05/11', 300000);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/reports/financial-series?from=1405/05/10&to=1405/05/11&granularity=day')
            ->assertOk();

        $rows = $response->json('data.rows');

        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(500000.0, $rows[0]['income'], 0.001);
        $this->assertEqualsWithDelta(300000.0, $rows[1]['income'], 0.001);
        $this->assertEqualsWithDelta(800000.0, $response->json('data.totals.income'), 0.001);
    }

    public function test_the_same_days_collapse_into_one_weekly_row(): void
    {
        $this->saleOn('1405/05/10', 500000);
        $this->saleOn('1405/05/11', 300000);

        // Both days sit in the same Shamsi week, which opened on 05/10.
        $rows = $this->actingAs($this->admin())
            ->getJson('/api/v1/reports/financial-series?from=1405/05/10&to=1405/05/16&granularity=week')
            ->assertOk()
            ->json('data.rows');

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(800000.0, $rows[0]['income'], 0.001);
    }

    public function test_cost_and_profit_are_carried_on_every_row(): void
    {
        $this->saleOn('1405/05/10', 500000);

        Expense::create([
            'title' => 'گازوئیل',
            'category' => 'fuel',
            'amount' => 200000,
            'spent_on' => Jalali::parse('1405/05/10')->toDateString(),
        ]);

        $rows = $this->actingAs($this->admin())
            ->getJson('/api/v1/reports/financial-series?from=1405/05/10&to=1405/05/10&granularity=day')
            ->assertOk()
            ->json('data.rows');

        $this->assertEqualsWithDelta(200000.0, $rows[0]['expense'], 0.001);
        $this->assertEqualsWithDelta(300000.0, $rows[0]['profit'], 0.001);
    }

    public function test_staff_cannot_read_the_financial_series(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $this->actingAs($seller)
            ->getJson('/api/v1/reports/financial-series')
            ->assertForbidden();
    }

    // ------------------------------------------------------- consumption

    public function test_the_consumption_series_keeps_baked_flour_apart_from_sold_flour(): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', 10000, 'purchase');

        $inside = Jalali::parse('1405/05/10')->setTime(9, 0);

        foreach ([['production', 400.0], ['spray', 5.0], ['flour_sale', 120.0]] as [$reason, $qty]) {
            $movement = $flour->move('out', $qty, $reason);
            DB::table('inventory_movements')
                ->where('id', $movement->id)->update(['created_at' => $inside]);
        }

        $rows = $this->actingAs($this->admin())
            ->getJson('/api/v1/reports/consumption-series?from=1405/05/10&to=1405/05/10&granularity=day')
            ->assertOk()
            ->json('data.rows');

        $this->assertEqualsWithDelta(400.0, $rows[0]['flour_production_kg'], 0.001);
        $this->assertEqualsWithDelta(5.0, $rows[0]['flour_spray_kg'], 0.001);
        $this->assertEqualsWithDelta(405.0, $rows[0]['flour_used_kg'], 0.001);
        // Sold on, so reported but never counted as consumption.
        $this->assertEqualsWithDelta(120.0, $rows[0]['flour_sold_kg'], 0.001);
    }

    public function test_consumption_totals_a_month_into_one_row(): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', 10000, 'purchase');

        foreach (['1405/05/05', '1405/05/20'] as $date) {
            $movement = $flour->move('out', 200, 'production');
            DB::table('inventory_movements')->where('id', $movement->id)
                ->update(['created_at' => Jalali::parse($date)->setTime(9, 0)]);
        }

        $rows = $this->actingAs($this->admin())
            ->getJson('/api/v1/reports/consumption-series?from=1405/05/01&to=1405/05/31&granularity=month')
            ->assertOk()
            ->json('data.rows');

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(400.0, $rows[0]['flour_used_kg'], 0.001);
    }

    // --------------------------------------------------------- Power BI

    public function test_the_export_returns_one_flat_row_per_sale(): void
    {
        $this->saleOn('1405/05/10', 500000);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/reports/export/sales?from=1405/05/01&to=1405/05/31')
            ->assertOk();

        $rows = $response->json('data.rows');

        $this->assertSame(1, $response->json('data.row_count'));
        $this->assertFalse($response->json('data.truncated'));
        $this->assertEqualsWithDelta(500000.0, $rows[0]['amount'], 0.001);
        $this->assertSame('نقد', $rows[0]['payment_label']);
        // The Shamsi date travels with the row so the report can slice on it.
        $this->assertSame('1405/05/10', Jalali::toLatinDigits($rows[0]['date_jalali']));
    }

    public function test_the_inventory_export_signs_each_movement(): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', 100, 'purchase');
        $flour->move('out', 40, 'production');

        $rows = $this->actingAs($this->admin())
            ->getJson('/api/v1/reports/export/inventory')
            ->assertOk()
            ->json('data.rows');

        $this->assertEqualsWithDelta(100.0, $rows[0]['signed_quantity'], 0.001);
        $this->assertEqualsWithDelta(-40.0, $rows[1]['signed_quantity'], 0.001);
    }

    public function test_an_unknown_dataset_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/reports/export/everything')
            ->assertNotFound();
    }

    // --------------------------------------------------- the health probe

    public function test_the_health_endpoint_answers_without_a_login(): void
    {
        // The app asks this while deciding which published address to talk
        // to, before anyone has signed in.
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson(['success' => true, 'service' => 'bakery']);
    }

    // ---------------------------------------------- the panel's own page

    /**
     * The shop's own month, not the calendar one.
     *
     * The flour quota runs from the 5th to the 4th and everything the shop
     * is judged on runs with it, so that is the window the page opens on.
     * The calendar month is a click away for the conversations that use it
     * — the partners' split, anything said outside the shop.
     */
    public function test_the_reports_page_opens_on_the_quota_period(): void
    {
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$start, $end] = Jalali::currentQuotaPeriod();

        Livewire::test(Reports::class)
            ->assertOk()
            ->assertSet('from', Jalali::date($start))
            ->assertSet('to', Jalali::date($end))
            ->assertSet('granularity', PeriodBuckets::DAY);
    }

    public function test_the_calendar_month_is_one_click_away(): void
    {
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$start, $end] = Jalali::currentMonthRange();

        Livewire::test(Reports::class)
            ->callAction('calendarMonth')
            ->assertSet('from', Jalali::date($start))
            ->assertSet('to', Jalali::date($end));
    }

    public function test_the_previous_period_is_the_one_that_ended_yesterday(): void
    {
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$thisStart] = Jalali::currentQuotaPeriod();
        [$lastStart, $lastEnd] = Jalali::quotaPeriodFor($thisStart->copy()->subDay());

        Livewire::test(Reports::class)
            ->callAction('previousQuotaPeriod')
            ->assertSet('from', Jalali::date($lastStart))
            ->assertSet('to', Jalali::date($lastEnd));

        // The two windows meet without a gap or an overlap, which is the
        // only way a figure cannot be counted twice or dropped.
        $this->assertTrue($lastEnd->copy()->addDay()->isSameDay($thisStart));
    }

    public function test_the_granularity_survives_a_range_change(): void
    {
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Reports::class)
            ->set('granularity', PeriodBuckets::WEEK)
            ->callAction('calendarMonth')
            // Changing the window is not a reason to throw away how the
            // owner asked to see it.
            ->assertSet('granularity', PeriodBuckets::WEEK);
    }

    public function test_the_reports_page_reads_all_three_tabs_over_one_range(): void
    {
        $this->saleOn('1405/05/10', 500000);

        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', 10000, 'purchase');
        $movement = $flour->move('out', 400, 'production');
        DB::table('inventory_movements')->where('id', $movement->id)
            ->update(['created_at' => Jalali::parse('1405/05/10')->setTime(9, 0)]);

        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::test(Reports::class)
            ->set('from', '1405/05/10')
            ->set('to', '1405/05/10')
            ->set('granularity', PeriodBuckets::DAY);

        $this->assertEqualsWithDelta(500000.0, (float) $page->instance()->financialRows()->sum('income'), 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $page->instance()->consumptionRows()->sum('flour_used_kg'), 0.001);
        $this->assertSame(10, (int) $page->instance()->productionRows()->sum('bread_sold'));

        // All three tabs render together, each with its own totals row, so a
        // broken column in one of them cannot hide behind an unopened tab.
        $page->assertSee('جمع درآمد')
            ->assertSee('خمیرگیری، چانه و فروش')
            ->assertSee('مصرف آرد، نمک و خمیرمایه');
    }

    public function test_dates_entered_the_wrong_way_round_still_read_the_range(): void
    {
        $this->saleOn('1405/05/10', 500000);

        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::test(Reports::class)
            ->set('from', '1405/05/12')
            ->set('to', '1405/05/08');

        $this->assertEqualsWithDelta(500000.0, (float) $page->instance()->financialRows()->sum('income'), 0.001);
    }

    public function test_a_half_typed_date_does_not_break_the_page(): void
    {
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Falls back to the current Jalali month rather than throwing while
        // the admin is still typing.
        Livewire::test(Reports::class)
            ->set('from', '۱۴۰۵/۰')
            ->assertOk();
    }

    public function test_staff_cannot_pull_the_export(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $this->actingAs($seller)
            ->getJson('/api/v1/reports/export/sales')
            ->assertForbidden();
    }
}
