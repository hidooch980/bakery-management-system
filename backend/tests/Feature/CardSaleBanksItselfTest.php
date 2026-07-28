<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
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
        \App\Support\Money::forgetCache();

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
                'customer_id' => \App\Models\Customer::create([
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

    /** With no account configured the sale must still go through. */
    public function test_a_card_sale_without_a_default_account_still_records(): void
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

        $this->assertNull(Sale::first()->bank_account_id);
    }
}
