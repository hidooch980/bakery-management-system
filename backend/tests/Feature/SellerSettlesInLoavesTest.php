<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\IssueScanner;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\SellerSettlement;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shop settles with a seller in loaves — "I have accounted for five
 * hundred" — and expects the account clear by the end of the month.
 */
class SellerSettlesInLoavesTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private ChaneEntry $chane;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman', 'bread_price' => 10_000]);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true, 'name' => 'فروشنده آزمایشی']);
        $this->seller->assignRole('seller');

        $dough = DoughEntry::create([
            'user_id' => $this->seller->id,
            'bag_count' => 4,
            'status' => 'shaped',
        ]);

        $this->chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => 400,
            'normal_weight_kg' => 340,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'sold',
        ]);
    }

    /** Bread sold for cash the seller is still holding. */
    private function cashSale(int $loaves, int $daysAgo = 0): Sale
    {
        $sale = Sale::create([
            'user_id' => $this->seller->id,
            'chane_entry_id' => $this->chane->id,
            'payment_type' => 'cash',
            'bread_count' => $loaves,
            'amount' => $loaves * 10_000,
        ]);

        if ($daysAgo > 0) {
            $sale->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        }

        return $sale;
    }

    public function test_what_the_seller_owes_is_counted_in_loaves(): void
    {
        $this->cashSale(300);
        $this->cashSale(200);

        $owed = SellerSettlement::outstandingFor($this->seller);

        // Counted off the sales, not divided out of the money: a price
        // change must not restate a debt already on the books.
        $this->assertSame(500, $owed['loaves']);
        $this->assertSame(500, $owed['cash_loaves']);
        $this->assertEquals(5_000_000, $owed['total']);
    }

    public function test_settling_a_loaf_count_clears_the_matching_sales(): void
    {
        $first = $this->cashSale(300, daysAgo: 2);
        $second = $this->cashSale(200);

        SellerSettlement::applyLoaves($this->seller, 300);

        // Oldest first, as a payment would be.
        $this->assertNotNull($first->fresh()->cash_settled_on);
        $this->assertNull($second->fresh()->cash_settled_on);
        $this->assertSame(200, SellerSettlement::outstandingFor($this->seller)['loaves']);
    }

    public function test_settling_every_loaf_clears_the_account(): void
    {
        $this->cashSale(300);
        $this->cashSale(200);

        SellerSettlement::applyLoaves($this->seller, 500);

        $this->assertSame(0, SellerSettlement::outstandingFor($this->seller)['loaves']);
        $this->assertEquals(0, SellerSettlement::outstandingFor($this->seller)['total']);
    }

    public function test_loaves_beyond_the_debt_are_held_as_credit(): void
    {
        $this->cashSale(300);

        $result = SellerSettlement::applyLoaves($this->seller, 400);

        // A hundred loaves' worth more than was owed. Money held beyond
        // the debt is the seller's, not a negative bill.
        $this->assertEquals(1_000_000, $result['credit_left']);
    }

    public function test_a_shortfall_is_counted_in_loaves_too(): void
    {
        $sale = Sale::create([
            'user_id' => $this->seller->id,
            'chane_entry_id' => $this->chane->id,
            'payment_type' => 'cash',
            'bread_count' => 100,
            'amount' => 1_000_000,
            'shortfall_count' => 20,
            'shortfall_amount' => 200_000,
        ]);

        $owed = SellerSettlement::outstandingFor($this->seller);

        $this->assertSame(20, $owed['shortfall_loaves']);
        $this->assertSame(120, $owed['loaves']);
        $this->assertNotNull($sale->id);
    }

    public function test_no_bread_price_refuses_rather_than_settling_at_nothing(): void
    {
        Bakery::first()->update(['bread_price' => 0]);
        Money::forgetCache();

        $this->cashSale(300);

        // Settling at zero would mark the debt paid and take nothing off it.
        $this->expectException(\RuntimeException::class);
        SellerSettlement::applyLoaves($this->seller, 300);
    }

    // ------------------------------------------- settle by month end

    public function test_a_debt_carried_past_month_end_is_critical(): void
    {
        [$monthStart] = Jalali::currentMonthRange();

        $sale = $this->cashSale(100);
        $sale->forceFill([
            'created_at' => $monthStart->copy()->subDays(2),
        ])->save();

        $issue = app(IssueScanner::class)->scan()
            ->firstWhere('key', "seller-account-stale-{$this->seller->id}");

        $this->assertNotNull($issue);
        $this->assertSame('critical', $issue->severity);
        $this->assertStringContainsString('ماه گذشته', $issue->title);
    }

    public function test_last_months_debt_is_late_even_when_only_days_old(): void
    {
        [$monthStart] = Jalali::currentMonthRange();

        // The day before the month turned. Two days old, and already past
        // the deadline the shop set itself — the seven-day grace must not
        // swallow it.
        $sale = $this->cashSale(100);
        $sale->forceFill([
            'created_at' => $monthStart->copy()->subDay(),
        ])->save();

        $this->assertNotNull(
            app(IssueScanner::class)->scan()
                ->firstWhere('key', "seller-account-stale-{$this->seller->id}"),
        );
    }

    public function test_a_fresh_debt_inside_the_month_is_not_flagged(): void
    {
        $this->cashSale(100);

        $this->assertNull(
            app(IssueScanner::class)->scan()
                ->firstWhere('key', "seller-account-stale-{$this->seller->id}"),
        );
    }

    public function test_the_warning_says_how_many_loaves(): void
    {
        $sale = $this->cashSale(250);
        $sale->forceFill(['created_at' => now()->subDays(9)])->save();

        $issue = app(IssueScanner::class)->scan()
            ->firstWhere('key', "seller-account-stale-{$this->seller->id}");

        $this->assertNotNull($issue);
        $this->assertStringContainsString('250', $issue->detail);
        $this->assertStringContainsString('نان', $issue->detail);
    }
}
