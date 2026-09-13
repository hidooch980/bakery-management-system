<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\ChaneEntry;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\FlourSale;
use App\Models\InventoryItem;
use App\Models\Sale;
use App\Models\SettlementRequest;
use App\Models\User;
use App\Support\CashNeverBanked;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Putting back what was taken and never written down.
 *
 * The forward fixes left the drawer short by every cash settlement and
 * every counter sale of flour the shop had ever made. This repairs the
 * figures the shop actually recorded and refuses to invent the ones it
 * did not — a drawer holding money nobody handed over would be a worse
 * answer than one that is short, and it is the answer nobody could ever
 * disprove afterwards.
 */
class PuttingBackTheCashThatWasNeverBankedTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $owner;

    private BankAccount $till;

    private Bakery $bakery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->bakery = Bakery::first();
        $this->bakery->update(['currency' => 'toman', 'flour_bag_weight_kg' => 40]);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');

        $this->till = BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 0,
            'is_active' => true,
            'is_cash_box' => true,
        ]);

        InventoryItem::ofKey(InventoryItem::FLOUR)?->move('in', 5000, 'purchase', $this->owner->id);
    }

    private function tillBalance(): float
    {
        return (float) $this->till->fresh()->balance;
    }

    /**
     * A flour sale as the books hold it from before the fix: the row is
     * there and no account ever moved for it.
     *
     * Written the ordinary way and then stripped of its posting, rather
     * than created with the model's hooks switched off. Silencing the
     * hooks would also skip the ones that weigh the sack and take the
     * flour out of the store, and the fixture would be a row this shop
     * could never have produced.
     */
    private function oldFlourSale(array $attributes = []): FlourSale
    {
        $sale = FlourSale::create(array_merge([
            'user_id' => $this->owner->id,
            'payment_type' => 'cash',
            'unit' => FlourSale::KG,
            'quantity' => 40,
            'unit_price' => 25_000,
            'amount' => 1_000_000,
            'sold_on' => now()->subMonth(),
        ], $attributes));

        $sale->clearBankTransactions();

        return $sale;
    }

    private function oldSettlement(array $attributes = []): SettlementRequest
    {
        return SettlementRequest::create(array_merge([
            'user_id' => $this->seller->id,
            'amount' => 800_000,
            'cash_amount' => 800_000,
            'paid_cash' => 800_000,
            'paid_card' => 0,
            'confirmed_at' => now()->subMonth(),
            'confirmed_by' => $this->owner->id,
        ], $attributes));
    }

    public function test_it_puts_a_counter_sale_of_flour_into_the_drawer(): void
    {
        $this->oldFlourSale();

        $this->assertSame(0.0, $this->tillBalance());

        CashNeverBanked::repairFor($this->bakery);

        $this->assertEqualsWithDelta(1_000_000, $this->tillBalance(), 0.01);
    }

    public function test_it_puts_a_confirmed_handovers_cash_into_the_drawer(): void
    {
        $this->oldSettlement();

        CashNeverBanked::repairFor($this->bakery);

        $this->assertEqualsWithDelta(800_000, $this->tillBalance(), 0.01);
    }

    public function test_running_it_twice_leaves_the_same_ledger(): void
    {
        // The one thing a repair on live books must never get wrong.
        $this->oldFlourSale();
        $this->oldSettlement();

        CashNeverBanked::repairFor($this->bakery);
        $once = $this->tillBalance();
        $rows = BankTransaction::count();

        CashNeverBanked::repairFor($this->bakery);

        $this->assertEqualsWithDelta($once, $this->tillBalance(), 0.01);
        $this->assertSame($rows, BankTransaction::count());
    }

    public function test_flour_still_owed_for_is_left_alone(): void
    {
        $customer = Customer::create(['name' => 'مدرسه شهید بهشتی']);

        $this->oldFlourSale([
            'payment_type' => 'credit',
            'customer_id' => $customer->id,
        ]);

        CashNeverBanked::repairFor($this->bakery);

        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_flour_given_away_is_left_alone(): void
    {
        $this->oldFlourSale([
            'payment_type' => 'charity',
            'unit_price' => 0,
            'amount' => 0,
        ]);

        CashNeverBanked::repairFor($this->bakery);

        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_a_sale_that_already_named_an_account_is_not_moved(): void
    {
        $bank = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 0,
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->oldFlourSale(['bank_account_id' => $bank->id]);

        CashNeverBanked::repairFor($this->bakery);

        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_an_unconfirmed_request_is_not_banked(): void
    {
        // Nobody has agreed this money changed hands.
        $this->oldSettlement(['confirmed_at' => null, 'confirmed_by' => null]);

        CashNeverBanked::repairFor($this->bakery);

        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_a_shop_with_no_drawer_is_left_untouched(): void
    {
        // Inventing one would be this system deciding the shop has an
        // account it has never been told about.
        $this->till->update(['is_cash_box' => false]);

        $this->oldFlourSale();
        $this->oldSettlement();

        CashNeverBanked::repairFor($this->bakery);

        $this->assertSame(0, BankTransaction::where('reason', 'sale')->count());
    }

    public function test_the_row_says_it_was_written_afterwards(): void
    {
        // Somebody reading the drawer in a year has to be able to tell a
        // repair from a movement that happened on the day.
        $this->oldSettlement();

        CashNeverBanked::repairFor($this->bakery);

        $row = BankTransaction::where('bank_account_id', $this->till->id)->firstOrFail();

        $this->assertStringContainsString('ثبت گذشته', (string) $row->note);
        $this->assertStringContainsString($this->seller->name, (string) $row->note);
    }

    public function test_the_repair_is_dated_when_the_money_changed_hands(): void
    {
        // Not today. Putting a month of back-postings on one date would
        // make every report of this month wrong to fix last month's.
        $this->oldSettlement();

        CashNeverBanked::repairFor($this->bakery);

        $row = BankTransaction::where('bank_account_id', $this->till->id)->firstOrFail();

        $this->assertTrue(
            $row->occurred_on->isSameDay(now()->subMonth()),
            'A back-posting must carry the date the money moved.',
        );
    }

    public function test_the_audit_counts_before_anything_is_written(): void
    {
        $this->oldFlourSale();
        $this->oldSettlement();

        $audit = CashNeverBanked::auditFor($this->bakery);

        $this->assertSame(1, $audit['flour_sales']);
        $this->assertEqualsWithDelta(1_000_000, $audit['flour_toman'], 0.01);
        $this->assertSame(1, $audit['settlements']);
        $this->assertEqualsWithDelta(800_000, $audit['settlement_toman'], 0.01);
        $this->assertTrue($audit['has_till']);

        // Counting must not be doing.
        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_it_reports_the_cash_no_record_can_account_for(): void
    {
        // A handover settled straight from the panel or the phone stamped
        // the sales and wrote down nothing about how the money arrived.
        // That figure cannot be recovered, so it is counted and named
        // rather than guessed at.
        $dough = DoughEntry::create(['user_id' => $this->seller->id, 'bag_count' => 2]);
        $batch = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 2,
        ]);

        Sale::create([
            'chane_entry_id' => $batch->id,
            'user_id' => $this->seller->id,
            'payment_type' => 'cash',
            'bread_count' => 100,
            'amount' => 500_000,
            'cash_settled_on' => now()->subMonth(),
        ]);

        $audit = CashNeverBanked::auditFor($this->bakery);

        $this->assertEqualsWithDelta(500_000, $audit['unrecorded_toman'], 0.01);
    }

    public function test_cash_a_request_accounted_for_is_not_reported_as_missing(): void
    {
        $this->oldSettlement();

        CashNeverBanked::repairFor($this->bakery);

        $this->assertSame(0.0, CashNeverBanked::auditFor($this->bakery)['unrecorded_toman']);
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        // What is being approved should be a number, not a sentence — and
        // the rehearsal must not be the performance.
        $this->oldFlourSale();
        $this->oldSettlement();

        $this->artisan('cash:put-back --dry-run')
            ->expectsOutputToContain('چیزی نوشته نشد')
            ->assertSuccessful();

        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_the_command_asks_before_it_writes(): void
    {
        $this->oldFlourSale();

        $this->artisan('cash:put-back')
            ->expectsConfirmation('طبق جدول بالا در دفترها ثبت شود؟', 'no')
            ->assertSuccessful();

        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_the_command_writes_once_confirmed(): void
    {
        $this->oldFlourSale();
        $this->oldSettlement();

        $this->artisan('cash:put-back')
            ->expectsConfirmation('طبق جدول بالا در دفترها ثبت شود؟', 'yes')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(1_800_000, $this->tillBalance(), 0.01);
    }

    public function test_a_second_run_finds_nothing_to_do(): void
    {
        $this->oldFlourSale();

        CashNeverBanked::repairFor($this->bakery);

        $this->artisan('cash:put-back --dry-run')
            ->expectsOutputToContain('چیزی برای اصلاح نیست')
            ->assertSuccessful();
    }

    public function test_a_seller_named_like_the_note_is_still_repaired(): void
    {
        // Whether a handover has been put right is asked of the account,
        // not of the wording on the row. It was asked of the note first,
        // and no name could actually have defeated that — but a question
        // about where money is should not be answered by matching a
        // sentence somebody is free to rewrite.
        $this->seller->update(['name' => 'تسویه نقدی زاده']);

        $this->oldSettlement();

        CashNeverBanked::repairFor($this->bakery);

        $this->assertEqualsWithDelta(800_000, $this->tillBalance(), 0.01);
    }

    public function test_a_card_only_handover_is_not_treated_as_cash(): void
    {
        // The card share posts against the same request, so the mere
        // existence of a posting cannot mean the cash was banked. The card
        // goes to a bank and the cash to the drawer, and the drawer is
        // what is asked about.
        $bank = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 0,
            'is_active' => true,
            'is_default' => true,
        ]);

        $request = $this->oldSettlement(['paid_cash' => 300_000, 'paid_card' => 500_000]);

        $bank->record('in', 500_000, 'sale', $this->owner->id, $request, 'تسویه کارتخوان');

        CashNeverBanked::repairFor($this->bakery);

        $this->assertEqualsWithDelta(300_000, $this->tillBalance(), 0.01);
        $this->assertEqualsWithDelta(500_000, (float) $bank->fresh()->balance, 0.01);
    }
}
