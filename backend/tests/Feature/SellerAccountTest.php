<?php

namespace Tests\Feature;

use App\Filament\Widgets\SellerAccountsTable;
use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cash a seller holds, and any gap between the money they recorded and what
 * the bread was worth, sit on their temporary account until it is settled.
 */
class SellerAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'flour_bag_weight_kg' => 40,
            'water_ratio' => 0.6,
            'salt_ratio' => 0.015,
            'dough_loss_ratio' => 0,
            // Proving is measured in ProofGainTest; here the
            // formula's own arithmetic is what is under test.
            'proof_gain_ratio' => 0,
            'normal_chane_weight_kg' => 0.85,
            'bread_price' => 5000,
            'currency' => 'toman',
        ]);
        Money::forgetCache();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** Records a sale through the API, so the difference is computed for real. */
    private function sell(User $seller, string $paymentType, int $breadCount, ?float $amount): Sale
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 50, 'purchase');

        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_WET)->move('in', 50, 'purchase');
        $dough = $this->userWithRole('dough_maker');
        $chaneGir = $this->userWithRole('chane_gir');

        $this->actingAs($dough, 'sanctum')->postJson('/api/v1/dough-entries', ['bag_count' => 5]);

        $this->actingAs($chaneGir, 'sanctum')->postJson('/api/v1/chane-entries', [
            'dough_entry_id' => DoughEntry::latest('id')->first()->id,
            'chane_count' => $breadCount,
            'spray_flour_kg' => 0,
        ]);

        $this->actingAs($seller, 'sanctum')->postJson('/api/v1/sales', [
            'chane_entry_id' => ChaneEntry::latest('id')->first()->id,
            'payment_type' => $paymentType,
            'bread_count' => $breadCount,
            'amount' => $amount,
        ])->assertCreated();

        return Sale::latest('id')->first();
    }

    public function test_a_cash_sale_puts_its_money_on_the_sellers_account(): void
    {
        $seller = $this->userWithRole('seller');

        // 100 loaves at 5,000 is exactly 500,000 — no discrepancy.
        $sale = $this->sell($seller, 'cash', 100, 500_000);

        $this->assertEquals(0.0, (float) $sale->amount_difference);
        $this->assertEquals(500_000.0, $sale->cash_held);
        $this->assertEquals(500_000.0, $sale->seller_account_amount);
        $this->assertFalse($sale->is_seller_account_settled);
    }

    public function test_a_card_sale_leaves_no_cash_with_the_seller(): void
    {
        $seller = $this->userWithRole('seller');

        $sale = $this->sell($seller, 'card', 100, 500_000);

        // The money reached the bank on its own, so nothing is owed.
        $this->assertEquals(0.0, $sale->cash_held);
        $this->assertEquals(0.0, $sale->seller_account_amount);
    }

    public function test_taking_less_money_than_the_bread_was_worth_is_owed(): void
    {
        $seller = $this->userWithRole('seller');

        // 100 loaves are worth 500,000 but only 450,000 was taken in cash.
        $sale = $this->sell($seller, 'cash', 100, 450_000);

        $this->assertEquals(-50_000.0, (float) $sale->amount_difference);
        // They hold 450,000 and are short 50,000: the bread's full value.
        $this->assertEquals(500_000.0, $sale->seller_account_amount);
    }

    public function test_a_card_sale_that_came_up_short_still_owes_the_gap(): void
    {
        $seller = $this->userWithRole('seller');

        $sale = $this->sell($seller, 'card', 100, 450_000);

        // No cash held, but 50,000 of bread value is unaccounted for.
        $this->assertEquals(0.0, $sale->cash_held);
        $this->assertEquals(50_000.0, $sale->seller_account_amount);
    }

    public function test_the_difference_is_frozen_against_a_later_price_change(): void
    {
        $seller = $this->userWithRole('seller');

        $sale = $this->sell($seller, 'cash', 100, 500_000);
        $this->assertEquals(0.0, (float) $sale->amount_difference);

        Bakery::first()->update(['bread_price' => 9000]);

        // Raising the price today must not make yesterday's seller a debtor.
        $this->assertEquals(0.0, (float) $sale->fresh()->amount_difference);
    }

    public function test_a_credit_sale_is_the_customers_debt_not_the_sellers(): void
    {
        $seller = $this->userWithRole('seller');
        $customer = Customer::create(['name' => 'دبستان', 'type' => 'school']);

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 50, 'purchase');

        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_WET)->move('in', 50, 'purchase');
        $dough = $this->userWithRole('dough_maker');
        $chaneGir = $this->userWithRole('chane_gir');

        $this->actingAs($dough, 'sanctum')->postJson('/api/v1/dough-entries', ['bag_count' => 5]);
        $this->actingAs($chaneGir, 'sanctum')->postJson('/api/v1/chane-entries', [
            'dough_entry_id' => DoughEntry::latest('id')->first()->id,
            'chane_count' => 100,
            'spray_flour_kg' => 0,
        ]);

        $this->actingAs($seller, 'sanctum')->postJson('/api/v1/sales', [
            'chane_entry_id' => ChaneEntry::latest('id')->first()->id,
            'payment_type' => 'credit',
            'customer_id' => $customer->id,
            'bread_count' => 100,
            'amount' => 500_000,
        ])->assertCreated();

        $sale = Sale::latest('id')->first();

        // The customer owes the money, but the seller is the one who handed
        // the bread over, so it stays on their account until it is
        // collected — and no cash is claimed to be in their pocket.
        $this->assertEquals(0.0, $sale->cash_held);
        $this->assertEquals(500_000.0, $sale->open_credit);
        $this->assertEquals(500_000.0, $sale->seller_account_amount);

        $sale->update(['settled_on' => now()]);

        // Once the customer pays, it leaves the seller's account by itself.
        $this->assertEquals(0.0, $sale->fresh()->seller_account_amount);
        $this->assertSame(0, Sale::query()->sellerAccountOutstanding()->count());
    }

    public function test_the_panel_totals_and_settles_a_sellers_account(): void
    {
        $seller = $this->userWithRole('seller');
        $this->sell($seller, 'cash', 100, 500_000);
        $this->sell($seller, 'cash', 40, 200_000);

        $admin = $this->userWithRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(SellerAccountsTable::class);
        $text = preg_replace('/\s+/u', ' ', strip_tags($component->html()));

        // 500,000 plus 200,000 is held by this seller.
        $this->assertStringContainsString('700/000 تومان', $text);

        $component->callTableAction('settleSellerAccount', $seller, [
            'paid_cash' => 700_000,
            'paid_card' => 0,
        ]);

        $this->assertSame(0, Sale::query()->sellerAccountOutstanding()->count());
        $this->assertNotNull(Sale::first()->cash_settled_on);
    }

    // ------------------------- settling by how the money was handed over

    /** Puts a seller on 700,000 Toman and returns them with an admin ready. */
    private function sellerOwing700k(): User
    {
        $seller = $this->userWithRole('seller');
        $this->sell($seller, 'cash', 100, 500_000);
        $this->sell($seller, 'cash', 40, 200_000);

        $this->actingAs($this->userWithRole('admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $seller;
    }

    public function test_settling_all_in_cash_touches_no_bank_account(): void
    {
        $seller = $this->sellerOwing700k();
        $account = BankAccount::create(['title' => 'ملی', 'is_default' => true]);

        Livewire::test(SellerAccountsTable::class)
            ->callTableAction('settleSellerAccount', $seller, [
                'paid_cash' => 700_000,
                'paid_card' => 0,
            ]);

        $this->assertSame(0, Sale::query()->sellerAccountOutstanding()->count());
        // Cash stays in the till; it never was a bank movement.
        $this->assertSame(0, $account->transactions()->count());
    }

    public function test_settling_on_the_card_reaches_the_bank_account(): void
    {
        $seller = $this->sellerOwing700k();
        $account = BankAccount::create(['title' => 'ملی', 'is_default' => true]);

        Livewire::test(SellerAccountsTable::class)
            ->callTableAction('settleSellerAccount', $seller, [
                'paid_cash' => 0,
                'paid_card' => 700_000,
                'bank_account_id' => $account->id,
            ]);

        $this->assertSame(0, Sale::query()->sellerAccountOutstanding()->count());
        $this->assertEqualsWithDelta(700_000, (float) $account->transactions()->sum('amount'), 0.01);
    }

    public function test_a_split_handover_banks_only_the_card_share(): void
    {
        $seller = $this->sellerOwing700k();
        $account = BankAccount::create(['title' => 'ملی', 'is_default' => true]);

        Livewire::test(SellerAccountsTable::class)
            ->callTableAction('settleSellerAccount', $seller, [
                'paid_cash' => 450_000,
                'paid_card' => 250_000,
                'bank_account_id' => $account->id,
            ]);

        $this->assertSame(0, Sale::query()->sellerAccountOutstanding()->count());
        $this->assertEqualsWithDelta(250_000, (float) $account->transactions()->sum('amount'), 0.01);
    }

    public function test_parts_that_do_not_come_to_the_whole_are_refused(): void
    {
        $seller = $this->sellerOwing700k();
        BankAccount::create(['title' => 'ملی', 'is_default' => true]);

        Livewire::test(SellerAccountsTable::class)
            ->callTableAction('settleSellerAccount', $seller, [
                'paid_cash' => 100_000,
                'paid_card' => 0,
            ]);

        // Nothing settled: the account still carries the whole amount.
        $this->assertSame(2, Sale::query()->sellerAccountOutstanding()->count());
    }

    public function test_a_settled_account_drops_off_the_list(): void
    {
        $seller = $this->userWithRole('seller');
        $this->sell($seller, 'cash', 100, 500_000);

        Sale::query()->sellerAccountOutstanding()->update(['cash_settled_on' => now()]);

        $admin = $this->userWithRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(SellerAccountsTable::class)
            ->assertSee('حساب تسویه‌نشده‌ای وجود ندارد');
    }
}
