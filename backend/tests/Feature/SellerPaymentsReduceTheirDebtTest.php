<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\SellerAccountCredit;
use App\Models\SellerSettlementRecord;
use App\Models\SettlementRequest;
use App\Models\User;
use App\Support\Money;
use App\Support\SellerLedger;
use App\Support\SellerSettlement;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «تسویه‌ها از بدهیش کم نمیشه»: a seller's payment was spent on school
 * credit that no handover ever closes, so the money was taken and the debt
 * stayed. A payment now reaches only the seller's own debt.
 */
class SellerPaymentsReduceTheirDebtTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['bread_price' => 5000, 'currency' => 'toman']);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function sale(string $type, float $amount, array $extra = []): Sale
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

        $sale = Sale::create(array_merge([
            'chane_entry_id' => $chane->id,
            'user_id' => $this->seller->id,
            'bread_count' => 100,
            'payment_type' => $type,
            'amount' => $amount,
            'amount_difference' => 0,
        ], $extra));
        $this->travel(1)->minutes();

        return $sale;
    }

    private function pay(float $amount): SettlementRequest
    {
        $request = SettlementRequest::create([
            'user_id' => $this->seller->id,
            'amount' => $amount,
            'paid_cash' => $amount,
            'paid_card' => 0,
        ]);
        SellerSettlement::confirm($request, $this->admin);

        return $request->fresh();
    }

    private function balance(): float
    {
        return SellerSettlement::balanceFor($this->seller)['balance'];
    }

    public function test_school_credit_before_the_cash_does_not_swallow_a_payment(): void
    {
        $school = Customer::create(['name' => 'دبستان', 'type' => 'school']);
        $this->sale('schools', 400_000, ['customer_id' => $school->id]);
        $cash = $this->sale('cash', 300_000);
        $this->sale('cash', 200_000);

        $this->assertEquals(500_000.0, $this->balance());

        $this->pay(300_000);

        $this->assertNotNull($cash->fresh()->cash_settled_on);
        $this->assertEquals(200_000.0, $this->balance());
        // The school still owes the shop; that is not the seller's.
        $this->assertEquals(400_000.0, SellerSettlement::balanceFor($this->seller)['credit']);
    }

    public function test_a_partial_payment_leaves_a_record_in_the_history(): void
    {
        $this->sale('cash', 300_000);
        $this->sale('cash', 200_000);

        $request = $this->pay(350_000);

        $record = SellerSettlementRecord::where('settlement_request_id', $request->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('payment', $record->kind);
        $this->assertEquals(350_000.0, (float) $record->amount);
        // 300,000 closed one sale; 50,000 sits as prepayment against the next.
        $this->assertEquals(150_000.0, $this->balance());
        $this->assertEquals(50_000.0, SellerAccountCredit::balanceFor($this->seller->id));
    }

    public function test_reversing_a_partial_payment_puts_the_debt_back(): void
    {
        $this->sale('cash', 300_000);
        $this->sale('cash', 200_000);
        $request = $this->pay(350_000);

        $record = SellerSettlementRecord::where('settlement_request_id', $request->id)->first();
        SellerSettlement::reverse($record, $this->admin, 'اشتباه');

        $this->assertEquals(500_000.0, $this->balance());
    }

    public function test_paying_more_than_everything_is_kept_as_prepayment(): void
    {
        $this->sale('cash', 300_000);

        $this->pay(320_000);

        $this->assertEquals(-20_000.0, $this->balance());
    }

    public function test_the_ledger_agrees_with_the_screen_after_payments(): void
    {
        $this->sale('cash', 300_000);
        $this->sale('cash', 200_000, ['shortfall_count' => 4, 'shortfall_amount' => 20_000]);
        $this->pay(150_000);
        $this->pay(100_000);

        $r = SellerLedger::reconcile($this->seller);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertEquals(270_000.0, $r['ledger']);
        $this->assertNull(SellerLedger::warning($r));

        $entries = SellerLedger::entries($this->seller);
        $this->assertEquals(270_000.0, $entries->last()['balance']);
    }

    public function test_the_ledger_warns_and_the_repair_puts_an_old_account_right(): void
    {
        $school = Customer::create(['name' => 'دبستان', 'type' => 'school']);
        $this->sale('schools', 400_000, ['customer_id' => $school->id]);
        $this->sale('cash', 300_000);
        $this->sale('cash', 200_000);

        // What the old code did: the payment was confirmed and banked but
        // closed nothing, and the history never heard of it.
        SettlementRequest::create([
            'user_id' => $this->seller->id,
            'amount' => 300_000,
            'paid_cash' => 300_000,
            'paid_card' => 0,
            'confirmed_at' => now(),
            'confirmed_by' => $this->admin->id,
        ]);

        $r = SellerLedger::reconcile($this->seller);
        $this->assertFalse($r['ok']);
        $this->assertEquals(500_000.0, $r['shown']);
        $this->assertEquals(200_000.0, $r['ledger']);
        $this->assertNotNull(SellerLedger::warning($r));

        $this->artisan('sellers:repair-ledger', ['--dry-run' => true])->assertSuccessful();
        $this->assertEquals(500_000.0, $this->balance(), 'a dry run changes nothing');
        $this->assertSame(0, SellerSettlementRecord::count());

        $this->artisan('sellers:repair-ledger')->assertSuccessful();

        $this->assertEquals(200_000.0, $this->balance());
        $this->assertTrue(SellerLedger::reconcile($this->seller)['ok']);
        $this->assertSame(1, SellerSettlementRecord::where('kind', 'backfill')->count());
        $this->assertSame(1, SellerSettlementRecord::where('kind', 'correction')->count());

        // Twice is the same as once.
        $this->artisan('sellers:repair-ledger')->assertSuccessful();
        $this->assertEquals(200_000.0, $this->balance());
        $this->assertSame(2, SellerSettlementRecord::count());
    }

    public function test_a_bookkeeping_row_cannot_be_reversed(): void
    {
        $record = SellerSettlementRecord::create([
            'user_id' => $this->seller->id,
            'kind' => 'correction',
            'amount' => 1000,
            'sale_ids' => [],
            'credit_ids' => [],
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/seller-settlements/{$record->id}/reverse", ['reason' => 'x'])
            ->assertStatus(409);
    }

    public function test_bread_handed_back_counts_in_the_ledger(): void
    {
        $this->sale('cash', 100_000, ['shortfall_count' => 10, 'shortfall_amount' => 50_000]);
        $this->actingAs($this->admin);

        SellerSettlement::applyLoaves($this->seller, 10);

        $r = SellerLedger::reconcile($this->seller);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertEquals(100_000.0, $r['ledger']);
        $this->assertSame('loaves', SellerSettlementRecord::sole()->kind);
    }
}
