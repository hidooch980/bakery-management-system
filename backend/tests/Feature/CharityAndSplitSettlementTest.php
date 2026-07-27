<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\SettlementRequest;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bread given away, and a handover split between cash and the card reader.
 */
class CharityAndSplitSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private ChaneEntry $chane;

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

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->seller = User::factory()->create();
        $this->seller->assignRole('seller');

        $dough = DoughEntry::create([
            'user_id' => $this->admin->id,
            'bag_count' => 1,
            'status' => 'shaped',
        ]);

        $this->chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->admin->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'pending',
        ]);
    }

    // ----------------------------------------------------------- charity

    public function test_charity_bread_can_be_recorded(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $this->chane->id,
                'payments' => [
                    ['payment_type' => 'cash', 'bread_count' => 90, 'amount' => 450000],
                    ['payment_type' => 'charity', 'bread_count' => 10],
                ],
            ])
            ->assertCreated();

        $charity = Sale::where('payment_type', 'charity')->firstOrFail();

        $this->assertSame(10, (int) $charity->bread_count);
    }

    /**
     * Bread given away brings in no money by definition. Counting it as a
     * gap would hand the seller a debt for every loaf donated.
     */
    public function test_charity_does_not_become_a_money_gap(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $this->chane->id,
                'payments' => [
                    ['payment_type' => 'charity', 'bread_count' => 20],
                ],
            ])
            ->assertCreated();

        $charity = Sale::where('payment_type', 'charity')->firstOrFail();

        $this->assertNull($charity->amount_difference);

        $account = $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/sales/my-account')
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(0, $account['difference'], 0.01);
    }

    public function test_charity_still_accounts_for_the_bread_it_moved(): void
    {
        // The bread left the shop, so it must not also show as a shortfall
        // the seller has to answer for.
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $this->chane->id,
                'payments' => [
                    ['payment_type' => 'cash', 'bread_count' => 80, 'amount' => 400000],
                    ['payment_type' => 'charity', 'bread_count' => 20],
                ],
            ])
            ->assertCreated();

        $this->assertSame(0, (int) Sale::sum('shortfall_count'));
    }

    // ------------------------------------------- cash and card settlement

    private function cashSale(float $amount): void
    {
        Sale::create([
            'user_id' => $this->seller->id,
            'chane_entry_id' => $this->chane->id,
            'payment_type' => 'cash',
            'bread_count' => 20,
            'amount' => $amount,
        ]);
    }

    public function test_a_settlement_request_records_how_it_was_paid(): void
    {
        $this->cashSale(100000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', [
                'paid_cash' => 60000,
                'paid_card' => 40000,
            ])
            ->assertCreated();

        $request = SettlementRequest::firstOrFail();

        $this->assertEqualsWithDelta(60000, (float) $request->paid_cash, 0.01);
        $this->assertEqualsWithDelta(40000, (float) $request->paid_card, 0.01);
    }

    /** An older copy of the app sends no split; it all came by hand. */
    public function test_an_unsplit_request_is_taken_as_all_cash(): void
    {
        $this->cashSale(100000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests')
            ->assertCreated();

        $request = SettlementRequest::firstOrFail();

        $this->assertEqualsWithDelta(100000, (float) $request->paid_cash, 0.01);
        $this->assertEqualsWithDelta(0, (float) $request->paid_card, 0.01);
    }

    /**
     * The card share has already reached the bank on its own, so confirming
     * posts it to the account rather than treating it as cash taken by hand.
     */
    public function test_confirming_posts_the_card_share_to_the_bank(): void
    {
        $account = BankAccount::create([
            'title' => 'حساب اصلی',
            'is_default' => true,
        ]);

        $this->cashSale(100000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', [
                'paid_cash' => 70000,
                'paid_card' => 30000,
            ])
            ->assertCreated();

        $request = SettlementRequest::firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/settlement-requests/{$request->id}/confirm")
            ->assertOk();

        $this->assertEqualsWithDelta(30000, (float) $account->fresh()->balance, 0.01);
        $this->assertNotNull($request->fresh()->confirmed_at);
    }

    public function test_a_confirmed_request_clears_the_sellers_account(): void
    {
        $this->cashSale(100000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['paid_cash' => 100000])
            ->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/settlement-requests/'.SettlementRequest::first()->id.'/confirm')
            ->assertOk();

        $account = $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/sales/my-account')
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(0, $account['total'], 0.01);
    }

    // ------------------------------------- the admin app's seller accounts

    public function test_the_admin_app_lists_what_each_seller_owes(): void
    {
        $this->cashSale(100000);

        $sellers = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/seller-accounts')
            ->assertOk()
            ->json('data.sellers');

        $this->assertCount(1, $sellers);
        $this->assertSame($this->seller->name, $sellers[0]['name']);
        $this->assertEqualsWithDelta(100000, $sellers[0]['settleable'], 0.01);
        $this->assertNull($sellers[0]['request']);
    }

    public function test_a_pending_request_is_shown_next_to_the_seller(): void
    {
        $this->cashSale(100000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', [
                'paid_cash' => 60000,
                'paid_card' => 40000,
            ])
            ->assertCreated();

        $sellers = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/seller-accounts')
            ->assertOk()
            ->json('data.sellers');

        $this->assertNotNull($sellers[0]['request']);
        $this->assertStringContainsString('60,000', $sellers[0]['request']['paid_cash_formatted']);
        $this->assertStringContainsString('40,000', $sellers[0]['request']['paid_card_formatted']);
    }

    public function test_a_settled_seller_is_not_listed(): void
    {
        $sellers = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/seller-accounts')
            ->assertOk()
            ->json('data.sellers');

        $this->assertSame([], $sellers);
    }

    public function test_the_admin_can_settle_a_seller_directly(): void
    {
        $this->cashSale(100000);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle")
            ->assertOk();

        $account = $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/sales/my-account')
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(0, $account['total'], 0.01);
    }

    public function test_rejecting_a_request_leaves_the_account_open(): void
    {
        $this->cashSale(100000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['paid_cash' => 100000])
            ->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson(
                '/api/v1/settlement-requests/'.SettlementRequest::first()->id.'/reject',
                ['reason' => 'مبلغ تحویلی کمتر بود']
            )
            ->assertOk();

        $account = $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/sales/my-account')
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(100000, $account['total'], 0.01);
    }

    public function test_a_seller_cannot_confirm_their_own_request(): void
    {
        $this->cashSale(100000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['paid_cash' => 100000])
            ->assertCreated();

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests/'.SettlementRequest::first()->id.'/confirm')
            ->assertForbidden();
    }
}
