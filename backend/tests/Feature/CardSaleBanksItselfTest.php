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
use Tests\TestCase;

/**
 * Card money is taken by the reader and settled to the account without
 * anyone carrying it. Left unposted it sat in neither the seller's hands
 * nor the bank, so a day of card sales simply vanished from the books.
 */
class CardSaleBanksItselfTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private BankAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'currency' => 'toman',
            'flour_bag_weight_kg' => 40,
            'bread_price' => 5000,
        ]);
        Money::forgetCache();

        $this->seller = User::factory()->create();
        $this->seller->assignRole('seller');

        $this->account = BankAccount::create([
            'title' => 'حساب اصلی',
            'is_default' => true,
        ]);
    }

    private function chane(): ChaneEntry
    {
        $dough = DoughEntry::create([
            'user_id' => $this->seller->id,
            'bag_count' => 1,
            'status' => 'shaped',
        ]);

        return ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'pending',
        ]);
    }

    public function test_a_card_sale_reaches_the_bank(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $this->chane()->id,
                'payment_type' => 'card',
                'bread_count' => 100,
                'amount' => 500000,
            ])
            ->assertCreated();

        $this->assertEqualsWithDelta(500000, (float) $this->account->fresh()->balance, 0.01);
    }

    public function test_cash_stays_with_the_seller_and_not_the_bank(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $this->chane()->id,
                'payment_type' => 'cash',
                'bread_count' => 100,
                'amount' => 500000,
            ])
            ->assertCreated();

        // Nothing was deposited: that money is in the seller's pocket
        // until they hand it over.
        $this->assertEqualsWithDelta(0, (float) $this->account->fresh()->balance, 0.01);
        $this->assertNull(Sale::first()->bank_account_id);
    }

    public function test_credit_does_not_reach_the_bank_either(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $this->chane()->id,
                'payment_type' => 'credit',
                'bread_count' => 100,
                'amount' => 500000,
                // Credit is owed by a named buyer, so the sale needs one.
                'customer_id' => Customer::create([
                    'name' => 'دبستان', 'type' => 'school',
                ])->id,
            ])
            ->assertCreated();

        $this->assertEqualsWithDelta(0, (float) $this->account->fresh()->balance, 0.01);
    }

    public function test_a_split_batch_banks_only_the_card_share(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $this->chane()->id,
                'payments' => [
                    ['payment_type' => 'cash', 'bread_count' => 60, 'amount' => 300000],
                    ['payment_type' => 'card', 'bread_count' => 40, 'amount' => 200000],
                ],
            ])
            ->assertCreated();

        $this->assertEqualsWithDelta(200000, (float) $this->account->fresh()->balance, 0.01);
    }

    /**
     * With the default flag off, the shop's one bank still takes it.
     *
     * This used to assert the opposite — no default, no account named —
     * and that was the wrong half of the intent. What the test is for is
     * that the sale still records; where the money goes is a separate
     * question, and «nowhere» is the answer this project has spent a week
     * removing. «درامد کارتخوان فقط بره حساب سفید»: with one bank there is
     * nothing to decide, and losing the figure over a missing tick is the
     * silent gap, not the safe option.
     */
    public function test_a_card_sale_without_a_default_account_still_banks(): void
    {
        $this->account->update(['is_default' => false]);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $this->chane()->id,
                'payment_type' => 'card',
                'bread_count' => 100,
                'amount' => 500000,
            ])
            ->assertCreated();

        $this->assertSame($this->account->id, Sale::first()->bank_account_id);
    }

    /**
     * Two banks and no default is a question, not an answer.
     *
     * Picking the lowest id would be a guess about which account took the
     * money — and a wrong guess there is indistinguishable from a right one
     * afterwards, which is what makes it worse than a gap.
     */
    public function test_a_card_sale_with_two_banks_and_no_default_names_none(): void
    {
        $this->account->update(['is_default' => false]);

        BankAccount::create([
            'title' => 'حساب دوم',
            'opening_balance' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $this->chane()->id,
                'payment_type' => 'card',
                'bread_count' => 100,
                'amount' => 500000,
            ])
            ->assertCreated();

        $this->assertNull(Sale::first()->bank_account_id);
    }
}
