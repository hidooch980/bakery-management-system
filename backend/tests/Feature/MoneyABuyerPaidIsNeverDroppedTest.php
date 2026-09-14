<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\Customer;
use App\Models\CustomerCredit;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A buyer's payment is recorded in full, whatever it lands on.
 *
 * An invoice settles whole, so a school paying ۲۵۰ against three invoices
 * of ۱۰۰ closes two and leaves ۵۰ with nowhere to go. That ۵۰ used to be
 * dropped: no account received it, the debt did not move by it, and the
 * seller was told the collection had succeeded. The money was in their
 * hand and nothing in the shop knew it existed.
 *
 * The remainder is now held against the buyer and spent on their next
 * payment — the same answer `seller_account_credits` already gives for the
 * same question on the other side of the counter.
 */
class MoneyABuyerPaidIsNeverDroppedTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private ChaneEntry $chane;

    private Customer $school;

    private BankAccount $till;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman', 'bread_price' => 5000]);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        $this->till = BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 0,
            'is_active' => true,
            'is_cash_box' => true,
        ]);

        $this->bank = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 0,
            'is_active' => true,
            'is_default' => true,
        ]);

        $dough = DoughEntry::create([
            'user_id' => $this->seller->id,
            'bag_count' => 1,
            'status' => 'shaped',
        ]);

        $this->chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'sold',
        ]);

        $this->school = Customer::create(['name' => 'دبستان', 'type' => 'school']);
    }

    private function invoices(float ...$amounts): void
    {
        foreach ($amounts as $amount) {
            Sale::create([
                'user_id' => $this->seller->id,
                'chane_entry_id' => $this->chane->id,
                'payment_type' => 'schools',
                'customer_id' => $this->school->id,
                'bread_count' => 10,
                'amount' => $amount,
            ]);
        }
    }

    private function collect(float $amount, string $method = 'cash')
    {
        return $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/my-collections/{$this->school->id}/collect", [
                'amount' => $amount,
                'method' => $method,
            ]);
    }

    private function stillOwed(): float
    {
        return round((float) Sale::where('customer_id', $this->school->id)
            ->outstanding()->sum('amount'), 2);
    }

    private function tillBalance(): float
    {
        return round((float) $this->till->fresh()->balance, 2);
    }

    public function test_the_whole_payment_reaches_the_till(): void
    {
        $this->invoices(100_000, 100_000, 100_000);

        $this->collect(250_000)->assertOk();

        $this->assertEqualsWithDelta(250_000, $this->tillBalance(), 0.01);
    }

    public function test_what_no_invoice_swallowed_is_held_against_the_buyer(): void
    {
        $this->invoices(100_000, 100_000, 100_000);

        $this->collect(250_000)->assertOk();

        $this->assertEqualsWithDelta(100_000, $this->stillOwed(), 0.01);
        $this->assertEqualsWithDelta(
            50_000,
            CustomerCredit::balanceFor($this->school->id),
            0.01,
        );
    }

    public function test_the_credit_pays_first_on_the_next_collection(): void
    {
        $this->invoices(100_000, 100_000, 100_000);

        $this->collect(250_000)->assertOk();

        // ۵۰ is already held, so ۵۰ more closes the last invoice.
        $this->collect(50_000)->assertOk();

        $this->assertEqualsWithDelta(0, $this->stillOwed(), 0.01);
        $this->assertEqualsWithDelta(
            0,
            CustomerCredit::balanceFor($this->school->id),
            0.01,
        );
        $this->assertEqualsWithDelta(300_000, $this->tillBalance(), 0.01);
    }

    public function test_a_payment_too_small_for_any_invoice_is_kept_not_refused(): void
    {
        // It used to be refused outright, which sent the seller away
        // holding cash the shop had no row for.
        $this->invoices(100_000);

        $this->collect(40_000)->assertOk();

        $this->assertEqualsWithDelta(40_000, $this->tillBalance(), 0.01);
        $this->assertEqualsWithDelta(
            40_000,
            CustomerCredit::balanceFor($this->school->id),
            0.01,
        );
        $this->assertEqualsWithDelta(100_000, $this->stillOwed(), 0.01);
    }

    public function test_two_small_payments_close_the_invoice_between_them(): void
    {
        $this->invoices(100_000);

        $this->collect(40_000)->assertOk();
        $this->collect(60_000)->assertOk();

        $this->assertEqualsWithDelta(0, $this->stillOwed(), 0.01);
        $this->assertEqualsWithDelta(100_000, $this->tillBalance(), 0.01);
        $this->assertEqualsWithDelta(
            0,
            CustomerCredit::balanceFor($this->school->id),
            0.01,
        );
    }

    public function test_a_card_collection_never_lands_in_the_till(): void
    {
        // `cardAccount()` is the default account only while that account is
        // not the drawer, which `defaultAccount()` does not check. A shop
        // that has flagged its till as the default had card takings booked
        // as cash in hand, leaving the drawer high by exactly what the
        // reader took.
        $this->invoices(100_000);

        $this->collect(100_000, 'card')->assertOk();

        $this->assertEqualsWithDelta(100_000, (float) $this->bank->fresh()->balance, 0.01);
        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_card_money_is_not_banked_in_a_till_that_is_the_default(): void
    {
        $this->bank->update(['is_default' => false]);
        $this->till->update(['is_default' => true]);

        $this->invoices(100_000);

        $this->collect(100_000, 'card')->assertOk();

        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_more_than_is_owed_is_still_refused(): void
    {
        $this->invoices(100_000);

        $this->collect(150_000)->assertStatus(422);

        $this->assertSame(0.0, $this->tillBalance());
        $this->assertSame(0, CustomerCredit::count());
    }

    public function test_credit_already_held_counts_against_what_can_be_taken(): void
    {
        // ۱۰۰ owed with ۴۰ already held is ۶۰ left to pay, and asking for
        // ۱۰۰ again would be charging the school twice for the same bread.
        $this->invoices(100_000);

        $this->collect(40_000)->assertOk();
        $this->collect(100_000)->assertStatus(422);
    }

    public function test_the_seller_is_told_what_is_being_held(): void
    {
        $this->invoices(100_000, 100_000, 100_000);

        $message = $this->collect(250_000)->assertOk()->json('message');

        $this->assertStringContainsString('باقی‌مانده', $message);
    }
}
