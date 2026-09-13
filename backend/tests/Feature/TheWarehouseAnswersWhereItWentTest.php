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
}
