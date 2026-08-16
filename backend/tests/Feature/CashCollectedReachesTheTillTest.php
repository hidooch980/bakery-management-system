<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A debt collected in cash lands in the drawer.
 *
 * The card half of this always worked. Cash was described as "staying in
 * the till" and went nowhere at all, because there was no till — money the
 * shop knew it had taken and could not say where it was. Every collection
 * now names an account, and which one depends on how the buyer paid.
 */
class CashCollectedReachesTheTillTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Customer $customer;

    private BankAccount $bank;

    private BankAccount $till;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        $this->customer = Customer::create(['name' => 'مدرسه شهید بهشتی']);

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

    /** A batch to hang the sale on: a sale without one cannot be saved. */
    private function batch(): ChaneEntry
    {
        $dough = DoughEntry::create([
            'user_id' => $this->seller->id,
            'bag_count' => 2,
        ]);

        return ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 2,
        ]);
    }

    private function debt(float $amount = 1_000_000): Sale
    {
        return Sale::create([
            'chane_entry_id' => $this->batch()->id,
            'user_id' => $this->seller->id,
            'customer_id' => $this->customer->id,
            'payment_type' => 'credit',
            'bread_count' => 100,
            'amount' => $amount,
        ]);
    }

    private function collect(float $amount, ?string $method): TestResponse
    {
        return $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/my-collections/{$this->customer->id}/collect", [
                'amount' => $amount,
                ...($method === null ? [] : ['method' => $method]),
            ]);
    }

    public function test_cash_lands_in_the_till(): void
    {
        $this->debt();

        $this->collect(1_000_000, 'cash')->assertOk();

        $this->assertEquals(1_000_000, $this->till->fresh()->balance);
        $this->assertEquals(0, $this->bank->fresh()->balance);
    }

    public function test_a_card_payment_still_reaches_the_bank(): void
    {
        $this->debt();

        $this->collect(1_000_000, 'card')->assertOk();

        $this->assertEquals(1_000_000, $this->bank->fresh()->balance);
        $this->assertEquals(0, $this->till->fresh()->balance);
    }

    public function test_saying_nothing_means_cash(): void
    {
        $this->debt();

        // The seller on the floor takes notes far more often than cards,
        // so an omitted method is the common case rather than an error.
        $this->collect(1_000_000, null)->assertOk();

        $this->assertEquals(1_000_000, $this->till->fresh()->balance);
    }

    public function test_only_what_cleared_an_invoice_is_banked(): void
    {
        $this->debt(1_000_000);
        $this->debt(1_000_000);

        // Enough for one invoice and part of the next: the part that
        // settles nothing is not money the shop can say it has.
        $this->collect(1_500_000, 'cash')->assertOk();

        $this->assertEquals(1_000_000, $this->till->fresh()->balance);
    }

    public function test_the_till_says_where_the_money_came_from(): void
    {
        $this->debt();

        $this->collect(1_000_000, 'cash')->assertOk();

        $posting = $this->till->transactions()->first();

        $this->assertSame('in', $posting->direction);
        $this->assertSame('settlement', $posting->reason);
        $this->assertStringContainsString('نقدی', $posting->note);
        $this->assertStringContainsString($this->customer->name, $posting->note);
    }

    public function test_a_shop_with_no_till_yet_still_settles_the_debt(): void
    {
        $this->till->delete();
        $this->debt();

        // The debt clearing is the point; the posting is bookkeeping. An
        // install that has not made a till must not fail the seller's
        // collection because of it.
        $this->collect(1_000_000, 'cash')->assertOk();

        $this->assertNotNull(Sale::first()->settled_on);
    }
}
