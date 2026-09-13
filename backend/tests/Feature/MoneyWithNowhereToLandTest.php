<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\FlourSale;
use App\Models\InventoryItem;
use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Models\User;
use App\Support\BalanceSheet;
use App\Support\CashNeverBanked;
use App\Support\IssueScanner;
use App\Support\Money;
use App\Support\SellerSettlement;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The six ways money still went nowhere after the drawer was built.
 *
 * Each one is the same shape as the holes the drawer was meant to close,
 * and each was opened or left behind by the work that closed them: wages
 * paid «از صندوق» posting nothing, card flour landing in the till, a
 * partial handover written off as a settlement, an audit calling properly
 * recorded money missing, a balance sheet dropping the sentence that
 * explains its own gap, and a command telling a shop with no drawer that
 * everything was already recorded.
 */
class MoneyWithNowhereToLandTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $owner;

    private BankAccount $bank;

    private BankAccount $till;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'currency' => 'toman',
            'bread_price' => 5000,
            'flour_bag_weight_kg' => 40,
        ]);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');

        $this->bank = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 0,
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->till = BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 0,
            'is_active' => true,
            'is_cash_box' => true,
        ]);
    }

    private function cashSale(float $amount = 1_000_000): Sale
    {
        $dough = DoughEntry::create([
            'user_id' => $this->seller->id,
            'bag_count' => 2,
        ]);

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

    // ------------------------------------------------ wages from the drawer

    public function test_wages_paid_from_the_drawer_leave_the_drawer(): void
    {
        // «از صندوق» is what the phone means when it sends no account.
        $payment = SalaryPayment::create([
            'user_id' => $this->seller->id,
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'base_amount' => 5_000_000,
            'paid_on' => now(),
            'bank_account_id' => null,
        ]);

        $this->assertEqualsWithDelta(-5_000_000, (float) $this->till->fresh()->balance, 0.01);
        $this->assertSame(0.0, (float) $this->bank->fresh()->balance);
        $this->assertSame(1, BankTransaction::where('reason', 'salary')->count());
        $this->assertNotNull($payment->fresh());
    }

    public function test_wages_not_yet_paid_move_nothing(): void
    {
        SalaryPayment::create([
            'user_id' => $this->seller->id,
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'base_amount' => 5_000_000,
            'paid_on' => null,
        ]);

        $this->assertSame(0.0, (float) $this->till->fresh()->balance);
    }

    // ------------------------------------------------ flour on the reader

    public function test_flour_sold_on_the_reader_does_not_land_in_the_drawer(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)?->move('in', 1000, 'purchase', $this->seller->id);

        FlourSale::create([
            'user_id' => $this->seller->id,
            'payment_type' => 'card',
            'unit' => FlourSale::KG,
            'quantity' => 40,
            'unit_price' => 25_000,
            'amount' => 1_000_000,
            'sold_on' => now(),
        ]);

        $this->assertEqualsWithDelta(1_000_000, (float) $this->bank->fresh()->balance, 0.01);
        $this->assertSame(0.0, (float) $this->till->fresh()->balance);
    }

    // ------------------------------------------------ a partial handover

    public function test_handing_over_less_than_is_owed_does_not_close_the_account(): void
    {
        $this->cashSale(1_000_000);
        $this->cashSale(1_000_000);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle", [
                'paid_cash' => 1_000_000,
                'paid_card' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('data.settled', false);

        // One sale paid for, one still owed — not two written off for the
        // price of one.
        $this->assertEqualsWithDelta(
            1_000_000,
            SellerSettlement::outstandingFor($this->seller)['total'],
            0.01,
        );
        $this->assertEqualsWithDelta(1_000_000, (float) $this->till->fresh()->balance, 0.01);
    }

    public function test_handing_over_the_whole_account_still_closes_it(): void
    {
        $this->cashSale(1_000_000);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle", [
                'paid_cash' => 1_000_000,
            ])
            ->assertOk()
            ->assertJsonPath('data.settled', true);

        $this->assertSame(0.0, round(SellerSettlement::outstandingFor($this->seller)['total'], 2));
    }

    // ------------------------------------------------ the audit's arithmetic

    public function test_a_handover_settled_from_the_phone_is_not_reported_as_missing(): void
    {
        $this->cashSale(1_000_000);

        SellerSettlement::settleWithMethod(
            $this->seller,
            $this->owner,
            cash: 1_000_000,
            card: 0,
        );

        // The money is in the drawer and a row says so. Counting only the
        // postings tied to a request called this unaccounted for.
        $audit = CashNeverBanked::auditFor(Bakery::first());

        $this->assertSame(0.0, $audit['unrecorded_toman']);
    }

    // ------------------------------------------------ the sheet's own note

    public function test_the_sheet_still_says_which_goods_have_no_price(): void
    {
        // Flour in the store, never bought through the system, so nothing
        // to value it at. The line is zero and the sentence explaining why
        // is the only thing on the sheet worth reading.
        InventoryItem::ofKey(InventoryItem::FLOUR)?->move('in', 400, 'purchase', $this->seller->id);

        $stock = collect(BalanceSheet::build()['assets'])->firstWhere('key', 'stock');

        $this->assertNotNull($stock);
        $this->assertStringContainsString('قیمتی برای این‌ها ثبت نشده', $stock['note']);
    }

    // ------------------------------------------------ nowhere to put it

    public function test_the_command_does_not_call_a_shop_with_no_drawer_finished(): void
    {
        $this->till->update(['is_cash_box' => false]);

        $this->cashSale(1_000_000);

        $this->artisan('cash:put-back --dry-run')
            ->expectsOutputToContain('صندوق')
            ->doesntExpectOutputToContain('چیزی برای اصلاح نیست')
            ->assertFailed();
    }

    // ------------------------------------------------ card with no bank

    public function test_a_shop_whose_only_account_is_the_drawer_is_told_about_the_reader(): void
    {
        $this->bank->update(['is_active' => false]);
        $this->till->update(['is_default' => true]);

        Sale::create([
            'chane_entry_id' => $this->cashSale()->chane_entry_id,
            'user_id' => $this->seller->id,
            'payment_type' => 'card',
            'bread_count' => 50,
            'amount' => 500_000,
        ]);

        $keys = (new IssueScanner)->scan()->pluck('key');

        $this->assertTrue($keys->contains('no-card-account'));
    }
}
