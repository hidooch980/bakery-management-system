<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\User;
use App\Support\IssueScanner;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two things the shop only found out about at the end of the month: money
 * sitting with a seller for weeks, and a month quietly spending more than
 * it takes.
 */
class StaleAndLossAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['bread_price' => 5000, 'currency' => 'toman']);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');
    }

    private function saleDaysAgo(int $days, float $amount = 500_000): Sale
    {
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
            'bread_count' => 100,
            'payment_type' => 'cash',
            'amount' => $amount,
            'amount_difference' => 0,
        ]);

        $sale->forceFill(['created_at' => now()->subDays($days)])->save();

        return $sale;
    }

    /** The key carries the Jalali month, so a new month is a new problem. */
    private function tradingAtALossRaised(): bool
    {
        foreach ($this->keys() as $key) {
            if (str_starts_with($key, 'trading-at-a-loss')) {
                return true;
            }
        }

        return false;
    }

    private function keys(): array
    {
        return (new IssueScanner)->scan()->pluck('key')->all();
    }

    public function test_money_held_for_a_fortnight_is_raised_as_critical(): void
    {
        $this->saleDaysAgo(15);

        $issue = (new IssueScanner)->scan()
            ->firstWhere('key', "seller-account-stale-{$this->seller->id}");

        $this->assertNotNull($issue);
        $this->assertSame('critical', $issue->severity);
    }

    public function test_a_week_old_balance_is_a_warning_not_a_crisis(): void
    {
        $this->saleDaysAgo(8);

        $issue = (new IssueScanner)->scan()
            ->firstWhere('key', "seller-account-stale-{$this->seller->id}");

        $this->assertNotNull($issue);
        $this->assertSame('warning', $issue->severity);
    }

    public function test_the_ordinary_rhythm_of_the_shop_is_not_an_alarm(): void
    {
        // Yesterday's takings are not a problem; raising them as one would
        // teach the admin to ignore the page.
        $this->saleDaysAgo(1);

        $this->assertNotContains(
            "seller-account-stale-{$this->seller->id}",
            $this->keys(),
        );
    }

    public function test_a_month_spending_more_than_it_takes_is_raised(): void
    {
        Expense::create([
            'user_id' => $this->seller->id,
            'category' => array_key_first(Expense::CATEGORIES),
            'title' => 'آرد',
            'amount' => 9_000_000,
            'spent_on' => now(),
        ]);

        // Only meaningful once there is enough month behind it; the shop
        // is mid-month in these tests.
        if (now()->day < 8) {
            $this->markTestSkipped('Too early in the month for the check to fire.');
        }

        $this->assertTrue($this->tradingAtALossRaised());
    }

    public function test_a_month_in_the_black_raises_nothing(): void
    {
        $this->saleDaysAgo(1, 9_000_000);

        $this->assertFalse($this->tradingAtALossRaised());
    }
}
