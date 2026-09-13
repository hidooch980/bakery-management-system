<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\FlourSale;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Flour sold over the counter lands in the drawer.
 *
 * The sale was always recorded and always counted as income by the Ledger,
 * so the profit figure was right. What was missing was the other half of
 * the sentence: no account ever moved unless somebody happened to pick one
 * on the form, and the form does not require it. The shop could say how
 * much flour it had sold and not where the money was.
 *
 * A sack still owed for is left alone. That money has not arrived, and
 * putting it in the drawer would be counting it twice — once here and
 * again when it is actually collected.
 */
class FlourSoldForCashReachesTheTillTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private BankAccount $bank;

    private BankAccount $till;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman', 'flour_bag_weight_kg' => 40]);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        InventoryItem::ofKey(InventoryItem::FLOUR)?->move('in', 1000, 'purchase', $this->seller->id);

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

    private function sell(array $attributes = []): FlourSale
    {
        return FlourSale::create(array_merge([
            'user_id' => $this->seller->id,
            'payment_type' => 'cash',
            'unit' => FlourSale::KG,
            'quantity' => 40,
            'unit_price' => 25_000,
            'amount' => 1_000_000,
            'sold_on' => now(),
        ], $attributes));
    }

    private function tillBalance(): float
    {
        return (float) $this->till->fresh()->balance;
    }

    public function test_a_cash_sale_with_no_account_named_goes_to_the_till(): void
    {
        $this->sell();

        $this->assertEqualsWithDelta(1_000_000, $this->tillBalance(), 0.01);
        $this->assertSame(0.0, (float) $this->bank->fresh()->balance);
    }

    public function test_a_named_account_still_wins(): void
    {
        // The drawer is the fallback, not an override. Somebody who says
        // the money went to the bank is telling the truth about it.
        $this->sell(['bank_account_id' => $this->bank->id]);

        $this->assertEqualsWithDelta(1_000_000, (float) $this->bank->fresh()->balance, 0.01);
        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_a_sack_still_owed_for_moves_no_money(): void
    {
        // The money has not arrived. Putting it in the drawer would count
        // it once here and again when it is collected.
        $customer = Customer::create(['name' => 'مدرسه شهید بهشتی']);

        $this->sell(['payment_type' => 'credit', 'customer_id' => $customer->id]);

        $this->assertSame(0.0, $this->tillBalance());
        $this->assertSame(0, BankTransaction::where('reason', 'flour_sale')->count());
    }

    public function test_flour_given_away_moves_no_money(): void
    {
        $this->sell(['payment_type' => 'charity', 'unit_price' => 0, 'amount' => 0]);

        $this->assertSame(0.0, $this->tillBalance());
        $this->assertSame(0, BankTransaction::where('reason', 'flour_sale')->count());
    }

    public function test_a_shop_with_no_drawer_records_the_sale_anyway(): void
    {
        // Losing the sale over a missing account would be a worse answer
        // than the one this replaces.
        $this->till->update(['is_cash_box' => false]);

        $sale = $this->sell();

        $this->assertNotNull($sale->fresh());
        $this->assertSame(0, BankTransaction::where('reason', 'flour_sale')->count());
    }
}
