<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The warehouse gets the report every other part of the shop already had.
 *
 * Production, sales, flour, efficiency, attendance, payroll, debts,
 * sellers, profit and loss and the balance sheet all have one. The
 * warehouse had a list of current balances and a raw feed of movements —
 * which answers «چقدر داریم» and never «کجا رفت».
 *
 * Flour alone had the second answer, in ReportSeries::flourJourney, and it
 * lived inside a Filament page: not on the API, so not on the phone, and
 * not for salt or yeast at all. The arithmetic was never flour-specific.
 */
class TheWarehouseAnswersWhereItWentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman', 'flour_bag_weight_kg' => 40]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function flour(): InventoryItem
    {
        return InventoryItem::ofKey(InventoryItem::FLOUR);
    }

    private function report(array $query = [])
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/reports/inventory?'.http_build_query($query));
    }

    public function test_it_says_where_each_good_went(): void
    {
        $flour = $this->flour();
        $flour->move('in', 400, 'purchase', $this->admin->id);
        $flour->move('out', 120, 'production', $this->admin->id);
        $flour->move('out', 40, 'flour_sale', $this->admin->id);

        $body = $this->report()->assertOk()->json('data');

        $row = collect($body['items'])->firstWhere('key', InventoryItem::FLOUR);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(400, $row['in_kg'], 0.01);
        $this->assertEqualsWithDelta(160, $row['out_kg'], 0.01);
        $this->assertEqualsWithDelta(240, $row['closing_kg'], 0.01);

        $out = collect($row['out'])->keyBy('reason');
        $this->assertEqualsWithDelta(120, $out['production']['kg'], 0.01);
        $this->assertEqualsWithDelta(40, $out['flour_sale']['kg'], 0.01);
    }

    public function test_every_stocked_good_is_reported_not_only_flour(): void
    {
        // Salt and yeast had a balance and nothing else. This is the whole
        // point of the endpoint.
        $keys = collect($this->report()->assertOk()->json('data.items'))->pluck('key');

        $this->assertContains(InventoryItem::SALT, $keys);
        $this->assertContains(InventoryItem::YEAST_DRY, $keys);
    }

    public function test_stock_that_moved_before_the_window_is_the_opening_balance(): void
    {
        $flour = $this->flour();

        $this->travelTo(now()->subDays(10));
        $flour->move('in', 500, 'purchase', $this->admin->id);
        $this->travelBack();

        $flour->move('out', 100, 'production', $this->admin->id);

        $row = collect($this->report(['from' => now()->subDays(2)->toDateString()])
            ->assertOk()->json('data.items'))->firstWhere('key', InventoryItem::FLOUR);

        // Not counted as arriving in the window, or the report would say a
        // delivery turned up that nobody made.
        $this->assertEqualsWithDelta(500, $row['opening_kg'], 0.01);
        $this->assertEqualsWithDelta(0, $row['in_kg'], 0.01);
        $this->assertEqualsWithDelta(400, $row['closing_kg'], 0.01);
    }

    public function test_the_report_says_whether_it_balances(): void
    {
        $this->flour()->move('in', 100, 'purchase', $this->admin->id);

        $row = collect($this->report()->assertOk()->json('data.items'))
            ->firstWhere('key', InventoryItem::FLOUR);

        $this->assertTrue($row['balances']);
    }

    public function test_a_seller_cannot_read_the_warehouse_report(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/reports/inventory')
            ->assertForbidden();
    }

    public function test_it_breaks_the_window_down_by_day(): void
    {
        // «حتماً من جای اشتباه کردم» — a thirty-day total cannot answer
        // that. Finding the day something went wrong needs the days.
        $flour = $this->flour();

        // Taken before travelling. `now()` inside the second travelTo
        // would be the *already frozen* time, so the two days would land
        // four days apart rather than two — and the test would hunt for a
        // date that was never written.
        $today = now();

        $this->travelTo($today->copy()->subDays(3)->setTime(9, 0));
        $flour->move('in', 400, 'purchase', $this->admin->id);

        $this->travelTo($today->copy()->subDays(1)->setTime(9, 0));
        $flour->move('out', 120, 'production', $this->admin->id);
        $flour->move('out', 40, 'flour_sale', $this->admin->id);
        $this->travelBack();

        $row = collect($this->report(['from' => now()->subDays(4)->toDateString()])
            ->assertOk()->json('data.items'))->firstWhere('key', InventoryItem::FLOUR);

        $days = collect($row['days'])->keyBy('date');

        $arrived = $days[now()->subDays(3)->toDateString()];
        $this->assertEqualsWithDelta(400, $arrived['in_kg'], 0.01);
        $this->assertEqualsWithDelta(0, $arrived['out_kg'], 0.01);

        $spent = $days[now()->subDays(1)->toDateString()];
        $this->assertEqualsWithDelta(160, $spent['out_kg'], 0.01);
    }

    public function test_each_day_carries_the_balance_it_ended_on(): void
    {
        // Reading down the closing column is how a day that does not make
        // sense is spotted without adding anything up by hand.
        $flour = $this->flour();

        $today = now();

        $this->travelTo($today->copy()->subDays(2)->setTime(9, 0));
        $flour->move('in', 500, 'purchase', $this->admin->id);

        $this->travelTo($today->copy()->subDays(1)->setTime(9, 0));
        $flour->move('out', 200, 'production', $this->admin->id);
        $this->travelBack();

        $days = collect(collect($this->report(['from' => now()->subDays(3)->toDateString()])
            ->assertOk()->json('data.items'))
            ->firstWhere('key', InventoryItem::FLOUR)['days'])->keyBy('date');

        $this->assertEqualsWithDelta(500, $days[now()->subDays(2)->toDateString()]['closing_kg'], 0.01);
        $this->assertEqualsWithDelta(300, $days[now()->subDays(1)->toDateString()]['closing_kg'], 0.01);
    }

    public function test_a_day_names_where_its_stock_went(): void
    {
        $flour = $this->flour();

        // Stocked first: the ledger refuses an «out» bigger than the
        // balance, and rightly — a shop cannot bake flour it does not
        // have. A fixture that skips it is testing an impossible day.
        $today = now();

        $this->travelTo($today->copy()->subDays(4)->setTime(9, 0));
        $flour->move('in', 500, 'purchase', $this->admin->id);

        $this->travelTo($today->copy()->subDays(1)->setTime(9, 0));
        $flour->move('out', 120, 'production', $this->admin->id);
        $flour->move('out', 40, 'flour_sale', $this->admin->id);
        $this->travelBack();

        $days = collect(collect($this->report(['from' => now()->subDays(3)->toDateString()])
            ->assertOk()->json('data.items'))
            ->firstWhere('key', InventoryItem::FLOUR)['days'])->keyBy('date');

        $out = collect($days[now()->subDays(1)->toDateString()]['out'])->keyBy('reason');

        $this->assertEqualsWithDelta(120, $out['production']['kg'], 0.01);
        $this->assertEqualsWithDelta(40, $out['flour_sale']['kg'], 0.01);
    }

    public function test_a_day_nothing_moved_is_left_out(): void
    {
        // A month of empty rows is a wall to scroll past, and the quiet
        // days are not what somebody hunting a mistake is looking for.
        $flour = $this->flour();

        $this->travelTo(now()->subDays(1)->setTime(9, 0));
        $flour->move('in', 100, 'purchase', $this->admin->id);
        $this->travelBack();

        $days = collect(collect($this->report(['from' => now()->subDays(10)->toDateString()])
            ->assertOk()->json('data.items'))
            ->firstWhere('key', InventoryItem::FLOUR)['days']);

        $this->assertCount(1, $days);
        $this->assertSame(now()->subDays(1)->toDateString(), $days->first()['date']);
    }

    public function test_each_day_carries_the_date_the_shop_writes(): void
    {
        $flour = $this->flour();

        $this->travelTo(now()->subDays(1)->setTime(9, 0));
        $flour->move('in', 100, 'purchase', $this->admin->id);
        $this->travelBack();

        $day = collect(collect($this->report(['from' => now()->subDays(3)->toDateString()])
            ->assertOk()->json('data.items'))
            ->firstWhere('key', InventoryItem::FLOUR)['days'])->first();

        // The shop reads Jalali. A report it has to convert in its head is
        // a report that gets read wrong.
        $this->assertNotEmpty($day['date_display']);
        $this->assertStringContainsString('/', $day['date_display']);
    }

    public function test_the_movement_list_can_be_narrowed_to_a_day(): void
    {
        // «ریز مصرف» — the individual entries behind a day's figure, with
        // who wrote each one. The endpoint has had them all along and took
        // no dates, so asking for one day meant paging through everything.
        $flour = $this->flour();
        $today = now();

        $this->travelTo($today->copy()->subDays(5)->setTime(9, 0));
        $flour->move('in', 500, 'purchase', $this->admin->id);

        $this->travelTo($today->copy()->subDays(2)->setTime(9, 0));
        $flour->move('out', 120, 'production', $this->admin->id);
        $this->travelBack();

        $day = $today->copy()->subDays(2)->toDateString();

        $rows = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/inventory/movements?'.http_build_query([
                'item' => InventoryItem::FLOUR,
                'from' => $day,
                'to' => $day,
            ]))
            ->assertOk()
            ->json('data.data');

        $this->assertCount(1, $rows);
        $this->assertSame('production', $rows[0]['reason']);
        $this->assertEqualsWithDelta(120, $rows[0]['quantity'], 0.01);
    }

    public function test_each_movement_says_who_wrote_it(): void
    {
        // A figure nobody's name is on cannot be asked about.
        $this->flour()->move('in', 100, 'purchase', $this->admin->id);

        $rows = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/inventory/movements?item='.InventoryItem::FLOUR)
            ->assertOk()
            ->json('data.data');

        $this->assertSame($this->admin->name, $rows[0]['user']['name']);
        $this->assertNotEmpty($rows[0]['created_at_display']);
    }

    public function test_a_day_outside_the_range_is_not_listed(): void
    {
        $flour = $this->flour();
        $today = now();

        $this->travelTo($today->copy()->subDays(9)->setTime(9, 0));
        $flour->move('in', 300, 'purchase', $this->admin->id);
        $this->travelBack();

        $rows = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/inventory/movements?'.http_build_query([
                'from' => $today->copy()->subDays(3)->toDateString(),
            ]))
            ->assertOk()
            ->json('data.data');

        $this->assertSame([], $rows);
    }
}
