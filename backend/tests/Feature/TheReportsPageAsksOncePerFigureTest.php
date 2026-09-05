<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\FlourSale;
use App\Models\Income;
use App\Models\InventoryItem;
use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Models\User;
use App\Support\ReportSeries;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The money series, asked once per figure rather than once per day.
 *
 * A month of daily buckets ran its own aggregate query for every figure in
 * every bucket: ninety-four sums over `sales` alone, and six hundred and
 * three queries for one page. This is the shape that once put 320 queries
 * on every panel page through a sidebar badge, and the rule written down
 * afterwards was to measure before and after.
 *
 * The figures are the point, not the saving. This file pins the numbers
 * first — every bucket's income, expense and profit against sums worked
 * out the slow, obvious way — so a change that makes the page quick and
 * the takings wrong cannot pass.
 */
class TheReportsPageAsksOncePerFigureTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');
    }

    /**
     * Trade spread over eight days, deliberately uneven: days with
     * nothing, days with several of one kind, and money on both sides.
     */
    private function tradeAcross(Carbon $start): void
    {
        // Flour in the store first: kneading and selling both take it out,
        // and the ledger refuses to go negative.
        InventoryItem::ofKey(InventoryItem::FLOUR)
            ->move('in', 10_000, 'purchase');

        foreach ([0, 0, 1, 3, 3, 3, 6, 7] as $i => $offset) {
            $day = $start->copy()->addDays($offset);

            $customer = Customer::create([
                'name' => "مشتری $i",
                'type' => 'school',
                'is_active' => true,
            ]);

            $dough = DoughEntry::create(['user_id' => $this->seller->id, 'bag_count' => 1]);
            $chane = ChaneEntry::create([
                'dough_entry_id' => $dough->id,
                'user_id' => $this->seller->id,
                'chane_count' => 100,
                'normal_weight_kg' => 85,
                'nanino_weight_kg' => 0,
                'spray_flour_kg' => 0,
                'status' => 'sold',
            ]);

            $sale = Sale::create([
                'chane_entry_id' => $chane->id,
                'user_id' => $this->seller->id,
                'customer_id' => $customer->id,
                'bread_count' => 10 + $i,
                'amount' => 123456 + $i,
                'payment_type' => 'schools',
            ]);
            $sale->forceFill(['created_at' => $day])->save();

            Expense::create([
                'title' => "هزینه $i",
                'category' => 'fuel',
                'amount' => 4321 + $i,
                'spent_on' => $day,
            ]);

            Income::create([
                'title' => "درآمد $i",
                'category' => 'other',
                'amount' => 777 + $i,
                'received_on' => $day,
            ]);

            FlourSale::create([
                'user_id' => $this->seller->id,
                'quantity' => 10,
                'unit' => 'kg',
                'weight_kg' => 10,
                'amount' => 5000 + $i,
                'sold_on' => $day,
            ]);
        }

        SalaryPayment::create([
            'user_id' => $this->seller->id,
            'period_start' => $start->copy()->startOfMonth(),
            'period_label' => 'شهریور',
            'gross_amount' => 9_000_000,
            'net_amount' => 8_500_000,
            'paid_on' => $start->copy()->addDays(3),
        ]);
    }

    /** The same figures, worked out the slow and obvious way. */
    private function theSlowWay(Carbon $from, Carbon $to): array
    {
        $income = (float) Sale::whereBetween('created_at', [$from, $to])->sum('amount')
            + (float) FlourSale::whereBetween('sold_on', [$from->toDateString(), $to->toDateString()])->sum('amount')
            + (float) Income::whereBetween('received_on', [$from->toDateString(), $to->toDateString()])->sum('amount');

        $expense = (float) Expense::whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])->sum('amount')
            + (float) SalaryPayment::paid()
                ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
                ->sum('net_amount');

        return [round($income, 2), round($expense, 2)];
    }

    public function test_every_bucket_reports_what_the_slow_count_says(): void
    {
        $start = Carbon::create(2026, 8, 20)->startOfDay();
        $this->tradeAcross($start);

        $from = $start->copy();
        $to = $start->copy()->addDays(9)->endOfDay();

        foreach (['day', 'week', 'month'] as $granularity) {
            $series = ReportSeries::financial($from, $to, $granularity);

            $this->assertNotEmpty($series, "no buckets for {$granularity}");

            foreach ($series as $bucket) {
                [$income, $expense] = $this->theSlowWay(
                    Carbon::parse($bucket['from'])->startOfDay(),
                    Carbon::parse($bucket['to'])->endOfDay(),
                );

                $where = "{$granularity} bucket {$bucket['key']}";

                $this->assertSame($income, $bucket['income'], "income wrong in {$where}");
                $this->assertSame($expense, $bucket['expense'], "expense wrong in {$where}");
                $this->assertSame(
                    round($income - $expense, 2),
                    $bucket['profit'],
                    "profit wrong in {$where}",
                );
            }
        }
    }

    public function test_the_totals_add_up_across_the_buckets(): void
    {
        $start = Carbon::create(2026, 8, 20)->startOfDay();
        $this->tradeAcross($start);

        $from = $start->copy();
        $to = $start->copy()->addDays(9)->endOfDay();

        $daily = ReportSeries::financial($from, $to, 'day');

        [$income, $expense] = $this->theSlowWay($from, $to);

        // Bucketing must not lose or invent money at the edges — a sale at
        // 23:59 belongs to that day and to the range, not to neither.
        $this->assertSame($income, round($daily->sum('income'), 2));
        $this->assertSame($expense, round($daily->sum('expense'), 2));
    }

    public function test_a_month_of_days_is_not_a_query_per_day(): void
    {
        $start = Carbon::create(2026, 8, 1)->startOfDay();
        $this->tradeAcross($start);

        DB::flushQueryLog();
        DB::enableQueryLog();

        ReportSeries::financial(
            $start->copy(),
            $start->copy()->addDays(30)->endOfDay(),
            'day',
        );

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Thirty-one buckets. Asked per bucket this was over a hundred and
        // fifty; the ceiling is per figure, not per day, so it must not
        // move with the length of the range.
        $this->assertLessThan(
            20,
            $queries,
            "a month of daily buckets took {$queries} queries",
        );
    }

    public function test_production_buckets_report_what_the_slow_count_says(): void
    {
        $start = Carbon::create(2026, 8, 20)->startOfDay();
        $this->tradeAcross($start);

        $from = $start->copy();
        $to = $start->copy()->addDays(9)->endOfDay();

        foreach (['day', 'week', 'month'] as $granularity) {
            foreach (ReportSeries::production($from, $to, $granularity) as $bucket) {
                $window = [
                    Carbon::parse($bucket['from'])->startOfDay(),
                    Carbon::parse($bucket['to'])->endOfDay(),
                ];

                $where = "{$granularity} bucket {$bucket['key']}";

                $this->assertSame(
                    DoughEntry::whereBetween('created_at', $window)->count(),
                    $bucket['dough_entries'],
                    "batch count wrong in {$where}",
                );
                $this->assertSame(
                    (float) DoughEntry::whereBetween('created_at', $window)->sum('bag_count'),
                    $bucket['bags_kneaded'],
                    "sacks wrong in {$where}",
                );
                $this->assertSame(
                    (int) ChaneEntry::whereBetween('created_at', $window)->sum('chane_count'),
                    $bucket['normal_chane_count'],
                    "chane wrong in {$where}",
                );
                $this->assertSame(
                    round((float) ChaneEntry::whereBetween('created_at', $window)->sum('normal_weight_kg'), 2),
                    $bucket['normal_weight_kg'],
                    "dough weight wrong in {$where}",
                );
                $this->assertSame(
                    (int) Sale::whereBetween('created_at', $window)->sum('bread_count'),
                    $bucket['bread_sold'],
                    "loaves wrong in {$where}",
                );
                $this->assertSame(
                    round((float) Sale::whereBetween('created_at', $window)->sum('amount'), 2),
                    $bucket['sales_amount'],
                    "takings wrong in {$where}",
                );
            }
        }
    }

    public function test_production_is_not_a_query_per_day_either(): void
    {
        $start = Carbon::create(2026, 8, 1)->startOfDay();
        $this->tradeAcross($start);

        DB::flushQueryLog();
        DB::enableQueryLog();

        ReportSeries::production(
            $start->copy(),
            $start->copy()->addDays(30)->endOfDay(),
            'day',
        );

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            20,
            $queries,
            "a month of production buckets took {$queries} queries",
        );
    }

    /** What a bucket's consumption figure is, counted the slow way. */
    private function usedTheSlowWay(string $key, array $reasons, Carbon $from, Carbon $to): float
    {
        $item = InventoryItem::query()->where('key', $key)->first();

        if ($item === null) {
            return 0.0;
        }

        return round((float) $item->movements()
            ->where('direction', 'out')
            ->whereIn('reason', $reasons)
            ->whereBetween('created_at', [$from, $to])
            ->sum('quantity'), 3);
    }

    public function test_consumption_buckets_report_what_the_slow_count_says(): void
    {
        $start = Carbon::create(2026, 8, 20)->startOfDay();
        $this->tradeAcross($start);

        $from = $start->copy();
        $to = $start->copy()->addDays(9)->endOfDay();

        foreach (['day', 'week', 'month'] as $granularity) {
            $series = ReportSeries::consumption($from, $to, $granularity);

            $this->assertNotEmpty($series, "no buckets for {$granularity}");

            foreach ($series as $bucket) {
                $bucketFrom = Carbon::parse($bucket['from'])->startOfDay();
                $bucketTo = Carbon::parse($bucket['to'])->endOfDay();
                $where = "{$granularity} bucket {$bucket['key']}";

                $production = $this->usedTheSlowWay(
                    InventoryItem::FLOUR, ['production'], $bucketFrom, $bucketTo,
                );
                $spray = $this->usedTheSlowWay(
                    InventoryItem::FLOUR, ['spray'], $bucketFrom, $bucketTo,
                );

                $this->assertSame($production, $bucket['flour_production_kg'], "kneaded flour wrong in {$where}");
                $this->assertSame($spray, $bucket['flour_spray_kg'], "bench flour wrong in {$where}");

                // The two ways a bakery eats flour, added. Flour sold on is
                // deliberately not in this total.
                $this->assertSame(
                    round($production + $spray, 3),
                    $bucket['flour_used_kg'],
                    "flour used wrong in {$where}",
                );

                $this->assertSame(
                    $this->usedTheSlowWay(
                        InventoryItem::FLOUR,
                        ['flour_sale', 'consignment_out'],
                        $bucketFrom,
                        $bucketTo,
                    ),
                    $bucket['flour_sold_kg'],
                    "flour sold wrong in {$where}",
                );

                $this->assertSame(
                    $this->usedTheSlowWay(InventoryItem::SALT, ['production'], $bucketFrom, $bucketTo),
                    $bucket['salt_kg'],
                    "salt wrong in {$where}",
                );

                $this->assertSame(
                    $this->usedTheSlowWay('yeast_dry', ['production'], $bucketFrom, $bucketTo),
                    $bucket['yeast_dry_kg'],
                    "yeast wrong in {$where}",
                );

                $this->assertSame(
                    (float) DoughEntry::whereBetween('created_at', [$bucketFrom, $bucketTo])->sum('bag_count'),
                    $bucket['bags_kneaded'],
                    "sacks wrong in {$where}",
                );
            }
        }
    }

    public function test_consumption_is_not_a_query_per_day(): void
    {
        $start = Carbon::create(2026, 8, 1)->startOfDay();
        $this->tradeAcross($start);

        DB::flushQueryLog();
        DB::enableQueryLog();

        ReportSeries::consumption(
            $start->copy(),
            $start->copy()->addDays(30)->endOfDay(),
            'day',
        );

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            20,
            $queries,
            "a month of consumption buckets took {$queries} queries",
        );
    }
}
