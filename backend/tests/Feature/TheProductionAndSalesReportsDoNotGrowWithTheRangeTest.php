<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two more reports measured the way the rule says: once small, once ten
 * times bigger, and any difference is the bug.
 *
 * The production report walked the range a day at a time and ran two
 * `->get()` for each — a three-month range was 240 questions, and it
 * loaded every batch and every chane row to add up four columns. The sales
 * report asked the users table once per seller for a name, inside a
 * grouping over sales it had already loaded.
 *
 * Both are now what the reports page was fixed into: grouped by date in
 * SQL, bucketed in PHP, names fetched once.
 */
class TheProductionAndSalesReportsDoNotGrowWithTheRangeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function queriesFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_production_report_costs_the_same_over_ninety_days_as_over_nine(): void
    {
        // Something on most days, so the loop has work to do either way.
        for ($day = 0; $day < 90; $day++) {
            DoughEntry::create(['user_id' => $this->admin->id, 'bag_count' => 4])
                ->forceFill(['created_at' => now()->subDays($day)])->save();
        }

        $to = now()->toDateString();

        // Once unmeasured, so a per-request lookup is not counted as a saving.
        $this->queriesFor('/api/v1/reports/production?from='.now()->subDays(8)->toDateString().'&to='.$to);

        $short = $this->queriesFor(
            '/api/v1/reports/production?from='.now()->subDays(8)->toDateString().'&to='.$to
        );

        $long = $this->queriesFor(
            '/api/v1/reports/production?from='.now()->subDays(89)->toDateString().'&to='.$to
        );

        $this->assertSame(
            $short,
            $long,
            "Nine days cost {$short} queries and ninety cost {$long}."
            .' The difference is per-day work inside the loop.'
        );
    }

    public function test_the_sales_report_costs_the_same_for_ten_sellers_as_for_one(): void
    {
        $this->sell(1);
        $this->queriesFor('/api/v1/reports/sales');
        $one = $this->queriesFor('/api/v1/reports/sales');

        $this->sell(9);
        $ten = $this->queriesFor('/api/v1/reports/sales');

        $this->assertSame(
            $one,
            $ten,
            "One seller cost {$one} queries and ten cost {$ten}."
            .' A name looked up per seller grows with the staff list.'
        );
    }

    /** Sellers with a sale apiece, so the report has each of them to name. */
    private function sell(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $seller = User::factory()->create(['is_active' => true]);
            $seller->assignRole('seller');

            $dough = DoughEntry::create([
                'user_id' => $seller->id,
                'bag_count' => 2,
                'status' => 'shaped',
            ]);

            $chane = ChaneEntry::create([
                'dough_entry_id' => $dough->id,
                'user_id' => $seller->id,
                'chane_count' => 200,
                'normal_weight_kg' => 170,
                'nanino_weight_kg' => 0,
                'spray_flour_kg' => 0,
                'status' => 'sold',
            ]);

            Sale::create([
                'user_id' => $seller->id,
                'chane_entry_id' => $chane->id,
                'payment_type' => 'cash',
                'bread_count' => 100,
                'amount' => 1_000_000,
            ]);
        }
    }
}
