<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
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
use RuntimeException;
use Tests\TestCase;

/**
 * What the books record for a handover is what the seller handed over.
 *
 * The split was already asked for and already posted to the right two
 * accounts. What was wrong was the figure: the app names the split under
 * `payments` and never sends `paid_cash`, and the default for an absent
 * `paid_cash` was the whole amount. So ۴۰۰ نقد و ۲۰۰ کارت was stored as
 * ۶۰۰ cash and ۲۰۰ card, and the drawer and the bank between them recorded
 * ۸۰۰ for ۶۰۰ handed over.
 *
 * The till read high by the card share of every split settlement the shop
 * had made, which is the kind of error that survives every reconciliation
 * of the books against themselves and is only ever found by counting.
 */
class AHandoverIsBankedForWhatWasHandedOverTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $owner;

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

    private function cashSale(float $amount): Sale
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

    /** The payload lib/widgets/seller_account_card.dart actually sends. */
    private function handOver(array $lines, ?float $amount = null)
    {
        return $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', array_filter([
                'payments' => [
                    ...array_map(
                        fn ($type, $sum) => ['payment_type' => $type, 'amount' => $sum],
                        array_keys($lines),
                        $lines,
                    ),
                ],
                'amount' => $amount,
            ], fn ($v) => $v !== null));
    }

    private function tillBalance(): float
    {
        return round((float) $this->till->fresh()->balance, 2);
    }

    private function bankBalance(): float
    {
        return round((float) $this->bank->fresh()->balance, 2);
    }

    public function test_a_split_handover_banks_exactly_what_was_handed_over(): void
    {
        $this->cashSale(600_000);

        $this->handOver(['cash' => 400_000, 'card' => 200_000])->assertCreated();

        SellerSettlement::confirm(SettlementRequest::first(), $this->owner);

        $this->assertEqualsWithDelta(400_000, $this->tillBalance(), 0.01);
        $this->assertEqualsWithDelta(200_000, $this->bankBalance(), 0.01);
        $this->assertEqualsWithDelta(
            600_000,
            $this->tillBalance() + $this->bankBalance(),
            0.01,
        );
    }

    public function test_the_split_is_stored_as_the_split_not_as_the_total(): void
    {
        $this->cashSale(600_000);

        $this->handOver(['cash' => 400_000, 'card' => 200_000])->assertCreated();

        $request = SettlementRequest::first();

        $this->assertEqualsWithDelta(400_000, (float) $request->paid_cash, 0.01);
        $this->assertEqualsWithDelta(200_000, (float) $request->paid_card, 0.01);
    }

    public function test_a_handover_named_only_as_an_amount_is_still_all_cash(): void
    {
        // What an older copy of the app sends: no split at all. The whole
        // handover is cash, which is what it always meant.
        $this->cashSale(600_000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', [])
            ->assertCreated();

        SellerSettlement::confirm(SettlementRequest::first(), $this->owner);

        $this->assertEqualsWithDelta(600_000, $this->tillBalance(), 0.01);
        $this->assertSame(0.0, $this->bankBalance());
    }

    public function test_bread_that_never_became_money_settles_without_being_banked(): void
    {
        // خیرات clears the debt because the bread went out of the shop, but
        // nobody paid for it. Posting it would put money in the drawer that
        // was never there.
        $this->cashSale(600_000);

        $this->handOver([
            'cash' => 400_000,
            'card' => 100_000,
            'charity' => 100_000,
        ])->assertCreated();

        SellerSettlement::confirm(SettlementRequest::first(), $this->owner);

        $this->assertEqualsWithDelta(400_000, $this->tillBalance(), 0.01);
        $this->assertEqualsWithDelta(100_000, $this->bankBalance(), 0.01);

        // And the account is clear all the same.
        $this->assertEqualsWithDelta(
            0,
            SellerSettlement::outstandingFor($this->seller->fresh())['total'],
            0.01,
        );
    }

    public function test_money_beyond_the_debt_being_cleared_is_refused(): void
    {
        $this->cashSale(600_000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', [
                'amount' => 300_000,
                'paid_cash' => 300_000,
                'paid_card' => 200_000,
            ])
            ->assertStatus(422);

        $this->assertSame(0, SettlementRequest::count());
    }

    public function test_a_request_with_no_amount_cannot_close_an_account(): void
    {
        // Not reachable through the endpoint, which refuses to write it.
        // The cost of being wrong is a seller's whole account cleared for
        // money nobody received, so it is refused here as well.
        $this->cashSale(600_000);

        $request = SettlementRequest::create([
            'user_id' => $this->seller->id,
            'amount' => 0,
            'paid_cash' => 0,
            'paid_card' => 0,
        ]);

        $this->expectException(RuntimeException::class);

        SellerSettlement::confirm($request, $this->owner);
    }

    public function test_that_refusal_leaves_the_account_exactly_as_it_was(): void
    {
        $this->cashSale(600_000);

        $request = SettlementRequest::create([
            'user_id' => $this->seller->id,
            'amount' => 0,
            'paid_cash' => 0,
            'paid_card' => 0,
        ]);

        try {
            SellerSettlement::confirm($request, $this->owner);
        } catch (RuntimeException) {
            // The point is what is left behind, not the message.
        }

        $this->assertEqualsWithDelta(
            600_000,
            SellerSettlement::outstandingFor($this->seller->fresh())['total'],
            0.01,
        );
        $this->assertNull($request->fresh()->confirmed_at);
        $this->assertSame(0.0, $this->tillBalance());
    }
}
