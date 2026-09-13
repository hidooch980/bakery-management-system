<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\SettlementRequest;
use App\Models\User;
use App\Support\Money;
use App\Support\SellerSettlement;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cash a seller hands over lands in the drawer.
 *
 * The card half always worked. Cash was described as «staying in the till»
 * and went nowhere at all — and the panel made that worse than a silent
 * omission: it asks the owner for the cash figure, refuses the form unless
 * cash and card add up to the account, then shows «نقد X • کارتخوان Y»
 * back. Every one of those numbers was thrown away. The owner typed it,
 * the screen confirmed it, and no account ever heard of it.
 *
 * The same hole was closed for customer collections in
 * CashCollectedReachesTheTillTest. Same shop, same money, two answers.
 */
class TheSellersCashReachesTheTillTest extends TestCase
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

        Bakery::first()->update(['currency' => 'toman', 'bread_price' => 5000]);
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

    private function tillBalance(): float
    {
        return (float) $this->till->fresh()->balance;
    }

    private function bankBalance(): float
    {
        return (float) $this->bank->fresh()->balance;
    }

    public function test_a_handover_paid_in_cash_reaches_the_till(): void
    {
        $this->cashSale(1_000_000);

        SellerSettlement::settleWithMethod(
            $this->seller,
            $this->owner,
            cash: 1_000_000,
            card: 0,
        );

        $this->assertEqualsWithDelta(1_000_000, $this->tillBalance(), 0.01);
        $this->assertSame(0.0, $this->bankBalance());
    }

    public function test_a_handover_split_between_cash_and_card_lands_in_both(): void
    {
        // The split is the whole point of asking for it. Putting it all in
        // one place would be a different lie from losing half of it.
        $this->cashSale(1_000_000);

        SellerSettlement::settleWithMethod(
            $this->seller,
            $this->owner,
            cash: 600_000,
            card: 400_000,
            account: $this->bank,
        );

        $this->assertEqualsWithDelta(600_000, $this->tillBalance(), 0.01);
        $this->assertEqualsWithDelta(400_000, $this->bankBalance(), 0.01);
    }

    public function test_confirming_a_sellers_request_banks_the_cash_they_said_they_paid(): void
    {
        $sale = $this->cashSale(800_000);

        $request = SettlementRequest::create([
            'user_id' => $this->seller->id,
            'amount' => 800_000,
            'cash_amount' => 800_000,
            'paid_cash' => 800_000,
            'paid_card' => 0,
            'sale_ids' => [$sale->id],
        ]);

        SellerSettlement::confirm($request, $this->owner);

        $this->assertEqualsWithDelta(800_000, $this->tillBalance(), 0.01);
    }

    public function test_nothing_handed_over_moves_nothing(): void
    {
        $this->cashSale(1_000_000);

        SellerSettlement::settleWithMethod(
            $this->seller,
            $this->owner,
            cash: 0,
            card: 0,
        );

        $this->assertSame(0.0, $this->tillBalance());
        $this->assertSame(0.0, $this->bankBalance());
        $this->assertSame(0, BankTransaction::count());
    }

    public function test_the_till_movement_says_whose_handover_it_was(): void
    {
        // A row in the drawer reading only «۱٬۰۰۰٬۰۰۰» is not an answer to
        // «این پول از کجا آمد».
        $this->cashSale(1_000_000);

        SellerSettlement::settleWithMethod(
            $this->seller,
            $this->owner,
            cash: 1_000_000,
            card: 0,
        );

        $movement = BankTransaction::where('bank_account_id', $this->till->id)->firstOrFail();

        $this->assertStringContainsString($this->seller->name, (string) $movement->note);
        $this->assertSame('in', $movement->direction);
    }

    public function test_settling_from_the_phone_puts_the_takings_in_the_till(): void
    {
        // The endpoint the owner's phone calls. It closed the account and
        // moved no money at all — and it sends no split, so silence has to
        // mean notes or the money is lost exactly as before.
        $this->cashSale(1_000_000);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle", [])
            ->assertOk();

        $this->assertEqualsWithDelta(1_000_000, $this->tillBalance(), 0.01);
    }

    public function test_the_phone_can_say_part_of_it_came_on_the_card(): void
    {
        $this->cashSale(1_000_000);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle", [
                'paid_cash' => 300_000,
                'paid_card' => 700_000,
                'bank_account_id' => $this->bank->id,
            ])
            ->assertOk();

        $this->assertEqualsWithDelta(300_000, $this->tillBalance(), 0.01);
        $this->assertEqualsWithDelta(700_000, $this->bankBalance(), 0.01);
    }

    public function test_more_than_the_debt_is_refused(): void
    {
        // Marking an account clear on a figure larger than it owes puts
        // money in the drawer that nobody handed over.
        $this->cashSale(1_000_000);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle", [
                'paid_cash' => 5_000_000,
            ])
            ->assertStatus(422);

        $this->assertSame(0.0, $this->tillBalance());
    }
}
