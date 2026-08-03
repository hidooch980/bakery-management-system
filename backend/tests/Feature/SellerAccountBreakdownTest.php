<?php

namespace Tests\Feature;

use App\Filament\Widgets\SellerAccountsTable;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\Customer;
use App\Models\DoughEntry;
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
 * A seller's temporary account covers everything they answer for: cash in
 * hand, a money gap, bread nobody paid for, and credit they handed out.
 */
class SellerAccountBreakdownTest extends TestCase
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

    /** Writes a sale directly, so each account component can be isolated. */
    private function sale(array $attributes): Sale
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

        return Sale::create([
            'chane_entry_id' => $chane->id,
            'user_id' => $this->seller->id,
            'bread_count' => 100,
            ...$attributes,
        ]);
    }

    private function account(): array
    {
        return $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/sales/my-account')
            ->assertOk()
            ->json('data');
    }

    public function test_unsettled_bread_shortfall_lands_on_the_account(): void
    {
        $this->sale([
            'payment_type' => 'card',
            'amount' => 450_000,
            'amount_difference' => 0,
            'shortfall_count' => 10,
            'shortfall_amount' => 50_000,
        ]);

        $account = $this->account();

        $this->assertEquals(50_000.0, $account['shortfall']);
        $this->assertSame(10, $account['shortfall_count']);
        // Card money reached the bank, so only the shortfall is owed.
        $this->assertEquals(0.0, $account['cash']);
        $this->assertEquals(50_000.0, $account['total']);
    }

    public function test_unpaid_credit_lands_on_the_account(): void
    {
        $customer = Customer::create(['name' => 'دبستان', 'type' => 'school']);

        $this->sale([
            'payment_type' => 'credit',
            'customer_id' => $customer->id,
            'amount' => 500_000,
            'amount_difference' => 0,
        ]);

        $account = $this->account();

        $this->assertEquals(500_000.0, $account['credit']);
        $this->assertEquals(500_000.0, $account['total']);
        $this->assertSame('دبستان', $account['credit_sales'][0]['customer']);
    }

    public function test_credit_the_customer_paid_leaves_the_account(): void
    {
        $customer = Customer::create(['name' => 'دبستان', 'type' => 'school']);

        $sale = $this->sale([
            'payment_type' => 'credit',
            'customer_id' => $customer->id,
            'amount' => 500_000,
            'amount_difference' => 0,
        ]);

        $sale->update(['settled_on' => now()]);

        $this->assertEquals(0.0, $this->account()['credit']);
    }

    public function test_a_settled_shortfall_leaves_the_account(): void
    {
        $sale = $this->sale([
            'payment_type' => 'card',
            'amount' => 450_000,
            'amount_difference' => 0,
            'shortfall_count' => 10,
            'shortfall_amount' => 50_000,
        ]);

        $sale->update(['shortfall_settled_on' => now()]);

        $this->assertEquals(0.0, $this->account()['shortfall']);
    }

    public function test_the_account_adds_every_kind_of_debt_together(): void
    {
        $customer = Customer::create(['name' => 'دبستان', 'type' => 'school']);

        // Cash held, with 20,000 less taken than the bread was worth.
        $this->sale([
            'payment_type' => 'cash',
            'amount' => 480_000,
            'amount_difference' => -20_000,
        ]);

        // Credit not yet collected.
        $this->sale([
            'payment_type' => 'credit',
            'customer_id' => $customer->id,
            'amount' => 300_000,
            'amount_difference' => 0,
        ]);

        // Bread nobody paid for.
        $this->sale([
            'payment_type' => 'card',
            'amount' => 200_000,
            'amount_difference' => 0,
            'shortfall_count' => 10,
            'shortfall_amount' => 50_000,
        ]);

        $account = $this->account();

        $this->assertEquals(480_000.0, $account['cash']);
        $this->assertEquals(-20_000.0, $account['difference']);
        $this->assertEquals(300_000.0, $account['credit']);
        $this->assertEquals(50_000.0, $account['shortfall']);
        // 480,000 + 300,000 + 50,000 + 20,000 owed on the gap.
        $this->assertEquals(850_000.0, $account['total']);
    }

    public function test_a_seller_only_ever_sees_their_own_account(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('seller');

        $this->sale(['payment_type' => 'cash', 'amount' => 500_000, 'amount_difference' => 0]);

        $this->assertEquals(0.0, $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/sales/my-account')
            ->assertOk()
            ->json('data.total'));
    }

    public function test_settling_clears_cash_and_shortfall_but_not_credit(): void
    {
        $customer = Customer::create(['name' => 'دبستان', 'type' => 'school']);

        $this->sale(['payment_type' => 'cash', 'amount' => 500_000, 'amount_difference' => 0]);
        $this->sale([
            'payment_type' => 'card',
            'amount' => 200_000,
            'amount_difference' => 0,
            'shortfall_count' => 10,
            'shortfall_amount' => 50_000,
        ]);
        $this->sale([
            'payment_type' => 'credit',
            'customer_id' => $customer->id,
            'amount' => 300_000,
            'amount_difference' => 0,
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(SellerAccountsTable::class)
            ->callTableAction('settleSellerAccount', $this->seller);

        $account = $this->account();

        $this->assertEquals(0.0, $account['cash']);
        $this->assertEquals(0.0, $account['shortfall']);
        // The customer still owes; the seller cannot clear that by paying.
        $this->assertEquals(300_000.0, $account['credit']);
        $this->assertEquals(300_000.0, $account['total']);
    }

    public function test_the_panel_breaks_the_account_into_its_parts(): void
    {
        $customer = Customer::create(['name' => 'دبستان', 'type' => 'school']);

        $this->sale(['payment_type' => 'cash', 'amount' => 500_000, 'amount_difference' => 0]);
        $this->sale([
            'payment_type' => 'credit',
            'customer_id' => $customer->id,
            'amount' => 300_000,
            'amount_difference' => 0,
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $html = Livewire::test(SellerAccountsTable::class)->html();
        $text = preg_replace('/\s+/u', ' ', strip_tags($html));

        $this->assertStringContainsString('نسیه وصول‌نشده', $text);
        $this->assertStringContainsString('کسری نان', $text);
        $this->assertStringContainsString('با پرداخت مشتری تسویه می‌شود', $text);
    }
}
