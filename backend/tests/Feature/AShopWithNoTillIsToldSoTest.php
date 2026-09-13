<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\IssueScanner;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shop that takes cash and has nowhere to record it should be told.
 *
 * Three paths bank cash through `BankAccount::cashBox()`: a seller's
 * handover, a customer's collection, and a counter sale of flour. Each was
 * fixed to stop losing the money, and each does nothing at all when no
 * account carries the flag — the same silence, one step further along.
 *
 * The flag could not be set from any screen until today, so this is not a
 * theoretical state: it is where this shop actually is until somebody
 * ticks the box.
 */
class AShopWithNoTillIsToldSoTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'currency' => 'toman',
            'bread_price' => 5000,
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 0.9,
        ]);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');
    }

    private function issues()
    {
        return (new IssueScanner)->scan();
    }

    private function noTillIssue()
    {
        return $this->issues()->firstWhere('key', 'no-cash-box');
    }

    private function till(): BankAccount
    {
        return BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 0,
            'is_active' => true,
            'is_cash_box' => true,
        ]);
    }

    private function cashSale(float $amount = 1_000_000): Sale
    {
        $dough = DoughEntry::create(['user_id' => $this->seller->id, 'bag_count' => 2]);

        $batch = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 2,
        ]);

        return Sale::create([
            'chane_entry_id' => $batch->id,
            'user_id' => $this->seller->id,
            'payment_type' => 'cash',
            'bread_count' => 100,
            'amount' => $amount,
        ]);
    }

    public function test_a_shop_holding_cash_with_no_till_is_told(): void
    {
        $this->cashSale();

        $issue = $this->noTillIssue();

        $this->assertNotNull($issue, 'cash is being held and no account can receive it');
        $this->assertSame('warning', $issue->severity);
    }

    public function test_it_says_how_much_is_waiting(): void
    {
        // The size is the argument. «یک حساب بسازید» is advice; «۱٬۲۰۰٬۰۰۰
        // ریال جایی برای ثبت ندارد» is a reason.
        $this->cashSale(1_200_000);

        $this->assertEqualsWithDelta(1_200_000, $this->noTillIssue()->magnitude, 0.01);
    }

    public function test_naming_a_till_settles_it(): void
    {
        $this->cashSale();
        $this->till();

        $this->assertNull($this->noTillIssue());
    }

    public function test_a_shop_holding_no_cash_is_not_nagged(): void
    {
        // Nothing has been taken, so nothing has been lost. A warning here
        // would be a setup checklist rather than a problem, and this page
        // is read every day.
        $this->assertNull($this->noTillIssue());
    }

    public function test_an_inactive_till_does_not_count(): void
    {
        // `cashBox()` only looks at active accounts, so a switched-off one
        // leaves the shop exactly as unable to record cash.
        $this->cashSale();
        $this->till()->update(['is_active' => false]);

        $this->assertNotNull($this->noTillIssue());
    }

    public function test_it_points_at_the_screen_that_fixes_it(): void
    {
        $this->cashSale();

        $this->assertSame('/admin/bank-accounts', $this->noTillIssue()->url);
    }
}
