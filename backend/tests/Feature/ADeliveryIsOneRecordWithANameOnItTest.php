<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\FlourPrice;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BalanceSheet;
use App\Support\Ledger;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A delivery is one record: the sacks, the money and the name.
 *
 * Every assertion here is a thing that used to be possible to get wrong,
 * because the three lived in three unconnected rows. What is checked is
 * not that a purchase saves — it is that the warehouse, the bank and the
 * mill's account all end up agreeing with it, on the edit path as well as
 * the create one.
 */
class ADeliveryIsOneRecordWithANameOnItTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BankAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->admin = $this->userWithRole('admin');

        $this->account = BankAccount::create([
            'title' => 'حساب اصلی',
            'opening_balance' => 100_000_000,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function flourBalance(): float
    {
        return InventoryItem::ofKey(InventoryItem::FLOUR)->balance;
    }

    /**
     * Twenty sacks of flour at 20,000 a kilo, half of it paid at the door.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function aDelivery(array $overrides = []): array
    {
        $payload = array_merge([
            'supplier_name' => 'کارخانه آرد زاهدان',
            'invoice_no' => 'A-1',
            'paid_amount' => 4_000_000,
            'items' => [
                ['item' => 'flour', 'bags' => 20, 'unit_price' => 20_000],
            ],
        ], $overrides);

        $id = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/purchases', $payload)
            ->assertCreated()
            ->json('data.id');

        return [$id, $payload];
    }

    // ------------------------------------------------- the goods and money

    public function test_a_delivery_fills_the_store_and_empties_the_account(): void
    {
        $flourBefore = $this->flourBalance();
        $bankBefore = $this->account->fresh()->balance;

        [$id] = $this->aDelivery();

        // Twenty sacks at the shop's 40 kg sack.
        $this->assertSame($flourBefore + 800.0, $this->flourBalance());

        // 800 kg at 20,000 is 16,000,000 invoiced; 4,000,000 was handed
        // over at the door and only that leaves the account.
        $purchase = Purchase::find($id);
        $this->assertSame(16_000_000.0, (float) $purchase->amount);
        $this->assertSame($bankBefore - 4_000_000.0, $this->account->fresh()->balance);

        // The rest is a debt with a name on it.
        $this->assertSame(12_000_000.0, $purchase->outstanding);
        $this->assertSame(12_000_000.0, $purchase->supplier->balance);
    }

    public function test_the_total_is_the_sum_of_the_lines_and_never_typed(): void
    {
        [$id] = $this->aDelivery([
            'items' => [
                ['item' => 'flour', 'bags' => 10, 'unit_price' => 20_000],
                ['title' => 'حمل', 'amount' => 1_500_000],
            ],
        ]);

        // 400 kg at 20,000 plus the lorry.
        $this->assertSame(9_500_000.0, (float) Purchase::find($id)->amount);

        // The freight line is money and never reaches the warehouse.
        $this->assertSame(400.0, $this->flourBalance());
    }

    public function test_a_line_with_no_goods_and_no_title_is_refused(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/purchases', [
                'supplier_name' => 'کارخانه',
                'items' => [['bags' => 5]],
            ])
            ->assertStatus(422);

        $this->assertSame(0, Purchase::count());
    }

    public function test_goods_delivered_with_no_money_are_refused(): void
    {
        // Flour that arrives and is not paid for is a consignment, which
        // this project already records elsewhere and differently.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/purchases', [
                'supplier_name' => 'کارخانه',
                'items' => [['item' => 'flour', 'bags' => 5]],
            ])
            ->assertStatus(422);
    }

    // ------------------------------------------------------- the edit path

    public function test_correcting_a_line_moves_the_flour(): void
    {
        [$id] = $this->aDelivery();
        $this->assertSame(800.0, $this->flourBalance());

        // Ten sacks, not twenty — the shape of this shop's most expensive
        // bug, where a record could be corrected and the goods could not.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/purchases/{$id}", [
                'items' => [['item' => 'flour', 'bags' => 10, 'unit_price' => 20_000]],
            ])
            ->assertOk();

        $this->assertSame(400.0, $this->flourBalance());
        $this->assertSame(8_000_000.0, (float) Purchase::find($id)->amount);
    }

    public function test_dropping_a_good_from_an_invoice_takes_its_stock_back(): void
    {
        [$id] = $this->aDelivery([
            'items' => [
                ['item' => 'flour', 'bags' => 5, 'unit_price' => 20_000],
                ['item' => 'salt', 'quantity_kg' => 50, 'unit_price' => 5_000],
            ],
        ]);

        $this->assertSame(200.0, $this->flourBalance());
        $this->assertSame(50.0, InventoryItem::ofKey(InventoryItem::SALT)->balance);

        // The salt line goes. It is no longer among the invoice's goods,
        // so nothing but the ledger remembers it moved — which is exactly
        // where the reversal has to come from.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/purchases/{$id}", [
                'items' => [['item' => 'flour', 'bags' => 5, 'unit_price' => 20_000]],
            ])
            ->assertOk();

        $this->assertSame(200.0, $this->flourBalance());
        $this->assertSame(0.0, InventoryItem::ofKey(InventoryItem::SALT)->balance);
    }

    public function test_correcting_what_was_paid_corrects_the_account(): void
    {
        [$id] = $this->aDelivery();
        $bankAfterCreate = $this->account->fresh()->balance;

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/purchases/{$id}", ['paid_amount' => 6_000_000])
            ->assertOk();

        // Two million more left the account, not a second posting of four.
        $this->assertSame($bankAfterCreate - 2_000_000.0, $this->account->fresh()->balance);
        $this->assertSame(10_000_000.0, Purchase::find($id)->outstanding);
    }

    public function test_deleting_an_invoice_gives_back_the_goods_and_the_money(): void
    {
        $flourBefore = $this->flourBalance();
        $bankBefore = $this->account->fresh()->balance;

        [$id] = $this->aDelivery();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/purchases/{$id}")
            ->assertOk();

        $this->assertSame($flourBefore, $this->flourBalance());
        $this->assertSame($bankBefore, $this->account->fresh()->balance);
        $this->assertSame(0.0, Supplier::first()->balance);
    }

    // ---------------------------------------------------------- the account

    public function test_a_payment_on_account_moves_the_balance_and_the_bank(): void
    {
        [$id] = $this->aDelivery();
        $supplier = Purchase::find($id)->supplier;
        $bankBefore = $this->account->fresh()->balance;

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/supplier-payments', [
                'supplier_id' => $supplier->id,
                'amount' => 5_000_000,
            ])
            ->assertCreated();

        $this->assertSame(7_000_000.0, $supplier->fresh()->balance);
        $this->assertSame($bankBefore - 5_000_000.0, $this->account->fresh()->balance);
    }

    public function test_a_payment_cannot_be_filed_against_another_suppliers_invoice(): void
    {
        [$id] = $this->aDelivery();
        $other = Supplier::create(['name' => 'بنکدار دیگر']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/supplier-payments', [
                'supplier_id' => $other->id,
                'purchase_id' => $id,
                'amount' => 1_000_000,
            ])
            ->assertStatus(422);
    }

    public function test_the_balances_screen_reports_what_is_owed(): void
    {
        $this->aDelivery();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/suppliers/balances')
            ->assertOk()
            ->assertJsonPath('data.suppliers.0.name', 'کارخانه آرد زاهدان')
            ->assertJsonPath('data.total_owed', 12_000_000);
    }

    public function test_a_supplier_with_history_is_not_deleted(): void
    {
        [$id] = $this->aDelivery();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/v1/suppliers/'.Purchase::find($id)->supplier_id)
            ->assertStatus(409);

        $this->assertSame(1, Supplier::count());
    }

    // ----------------------------------------------------------- the books

    public function test_a_delivery_is_counted_once_in_the_profit_statement(): void
    {
        $this->aDelivery();

        [$from, $to] = [now()->copy()->subDay(), now()->copy()->addDay()];

        // The invoice is a cost, counted from the invoice and nowhere else.
        $this->assertSame(16_000_000.0, Ledger::purchases($from, $to));
        $this->assertSame(16_000_000.0, Ledger::totalExpenses($from, $to));

        // Its flour is charged again as it is baked, so it comes out of
        // the operating side — the same rule the retired expense category
        // has always had.
        $this->assertSame(16_000_000.0, Ledger::flourPurchases($from, $to));
        $this->assertSame(0.0, Ledger::operatingExpenses($from, $to));
    }

    public function test_only_the_flour_lines_are_held_back_from_operating_costs(): void
    {
        $this->aDelivery([
            'items' => [
                ['item' => 'flour', 'bags' => 10, 'unit_price' => 20_000],
                ['title' => 'تخلیه', 'amount' => 500_000],
            ],
        ]);

        [$from, $to] = [now()->copy()->subDay(), now()->copy()->addDay()];

        $this->assertSame(8_000_000.0, Ledger::flourPurchases($from, $to));
        // Unloading is a real cost of running the shop and is not charged
        // a second time by the bake.
        $this->assertSame(500_000.0, Ledger::operatingExpenses($from, $to));
    }

    public function test_the_retired_expense_rows_are_still_counted(): void
    {
        // A delivery filed the old way, before purchases existed. The
        // category is no longer offered; the row still happened.
        Expense::create([
            'category' => 'flour',
            'title' => 'خرید آرد',
            'amount' => 3_000_000,
            'spent_on' => now(),
        ]);

        $this->aDelivery();

        [$from, $to] = [now()->copy()->subDay(), now()->copy()->addDay()];

        $this->assertSame(19_000_000.0, Ledger::flourPurchases($from, $to));
        $this->assertSame(19_000_000.0, Ledger::totalExpenses($from, $to));
    }

    public function test_the_retired_categories_are_refused_on_a_new_expense(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/expenses', [
                'category' => 'flour',
                'title' => 'خرید آرد',
                'amount' => 1_000,
                'spent_on' => '1405/05/03',
            ])
            ->assertStatus(422);
    }

    public function test_an_old_expense_row_can_still_be_corrected(): void
    {
        $expense = Expense::create([
            'category' => 'flour',
            'title' => 'خرید آرد',
            'amount' => 3_000_000,
            'spent_on' => now(),
        ]);

        // The row can be fixed without being forced into a category that
        // would move it to a different line of the profit statement.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/expenses/{$expense->id}", [
                'category' => 'flour',
                'amount' => 3_500_000,
            ])
            ->assertOk();

        $this->assertSame('خرید آرد', $expense->fresh()->category_label);
    }

    public function test_what_is_owed_reaches_the_balance_sheet(): void
    {
        $this->aDelivery();

        $sheet = BalanceSheet::build();
        $debt = collect($sheet['liabilities'])->firstWhere('key', 'supplier_debt');

        $this->assertNotNull($debt, 'A shop that owes a mill has to say so on its balance sheet.');
        $this->assertSame(12_000_000.0, $debt['amount']);
    }

    // ------------------------------------------------------------ the rate

    public function test_a_flour_line_sets_the_buying_price_for_that_day(): void
    {
        $this->aDelivery();

        $this->assertSame(20_000.0, FlourPrice::onDate(now()));
    }

    public function test_a_price_the_owner_typed_is_not_overruled_by_a_delivery(): void
    {
        FlourPrice::create([
            'price_per_kg' => 18_000,
            'effective_from' => now()->toDateString(),
            'note' => 'دستی',
        ]);

        $this->aDelivery();

        $this->assertSame(18_000.0, FlourPrice::onDate(now()));
    }

    public function test_a_second_flour_line_re_averages_the_rate_it_set(): void
    {
        $this->aDelivery([
            'items' => [
                // 400 kg at 20,000 and 400 kg at 30,000 is one load at
                // 25,000 — which is what the bread out of it cost.
                ['item' => 'flour', 'bags' => 10, 'unit_price' => 20_000],
                ['item' => 'flour', 'bags' => 10, 'unit_price' => 30_000],
            ],
        ]);

        $this->assertSame(25_000.0, FlourPrice::onDate(now()));

        // One rate for the load, not one per line.
        $this->assertSame(1, FlourPrice::whereNotNull('purchase_id')->count());
    }

    // ------------------------------------------------------- who may do it

    public function test_the_seller_may_write_a_delivery_down_but_not_read_the_account(): void
    {
        $seller = $this->userWithRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/purchases', [
                'supplier_name' => 'کارخانه',
                'items' => [['item' => 'flour', 'bags' => 5, 'unit_price' => 20_000]],
            ])
            ->assertCreated();

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/purchases/mine')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        // What the shop owes the mill is not theirs to see.
        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/suppliers/balances')
            ->assertForbidden();

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/purchases')
            ->assertForbidden();
    }

    public function test_production_staff_cannot_record_a_delivery(): void
    {
        $this->actingAs($this->userWithRole('dough_maker'), 'sanctum')
            ->postJson('/api/v1/purchases', [
                'supplier_name' => 'کارخانه',
                'items' => [['item' => 'flour', 'bags' => 1, 'unit_price' => 1]],
            ])
            ->assertForbidden();
    }

    public function test_the_form_options_answer_in_one_call(): void
    {
        Supplier::create(['name' => 'کارخانه آرد']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/purchases/options')
            ->assertOk()
            ->assertJsonPath('data.suppliers.0.name', 'کارخانه آرد')
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.accounts.0.title', 'حساب اصلی');
    }

    // --------------------------------------------------- the rest of it

    public function test_the_remaining_endpoints_answer(): void
    {
        [$id] = $this->aDelivery();
        $supplierId = Purchase::find($id)->supplier_id;

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/suppliers')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/suppliers/{$supplierId}", ['phone' => '09150000000'])
            ->assertOk()
            ->assertJsonPath('data.phone', '09150000000');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/purchases')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $paymentId = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/supplier-payments', [
                'supplier_id' => $supplierId,
                'purchase_id' => $id,
                'amount' => 2_000_000,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/supplier-payments')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/suppliers/{$supplierId}/account")
            ->assertOk()
            ->assertJsonPath('data.balance', 10_000_000)
            ->assertJsonCount(1, 'data.purchases')
            ->assertJsonCount(1, 'data.payments');

        // An invoice with a payment against it is not deleted out from
        // under it — the payment would be left pointing at nothing.
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/purchases/{$id}")
            ->assertStatus(409);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/supplier-payments/{$paymentId}")
            ->assertOk();

        $this->assertSame(12_000_000.0, Supplier::find($supplierId)->balance);
    }

    public function test_a_deactivated_supplier_leaves_the_picker_but_can_still_be_found(): void
    {
        // A bakery typed as «مدرسه» once vanished from a dropdown and
        // nothing reconciled a row that was never written. The filter is
        // right; being unable to see past it is not.
        $supplier = Supplier::create(['name' => 'کارخانه قدیمی', 'is_active' => false]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/suppliers')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/suppliers?all=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $supplier->id);
    }

    // -------------------------------------------------------------- money

    public function test_a_rial_shop_does_not_record_ten_times_what_it_paid(): void
    {
        Bakery::first()->update(['currency' => Money::RIAL]);
        Money::forgetCache();

        // Typed in Rial, because that is what the shop is set to.
        [$id] = $this->aDelivery([
            'paid_amount' => 40_000_000,
            'items' => [['item' => 'flour', 'bags' => 20, 'unit_price' => 200_000]],
        ]);

        $purchase = Purchase::find($id);

        // Stored in Toman, the way every other amount in this project is.
        $this->assertSame(16_000_000.0, (float) $purchase->amount);
        $this->assertSame(4_000_000.0, (float) $purchase->paid_amount);

        // And read back in the unit it was typed in.
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/purchases/{$id}")
            ->assertOk()
            ->assertJsonPath('data.amount', 160_000_000)
            ->assertJsonPath('data.outstanding', 120_000_000);
    }
}
