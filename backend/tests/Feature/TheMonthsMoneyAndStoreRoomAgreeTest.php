<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Support\BalanceSheet;
use App\Support\DoughFormula;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * گردشِ مالی و گردشِ انبار، آخرِ ماه، کنار هم.
 *
 * تستِ ماهانه («یک ماه کامل نانوایی») پول را تا ریالِ آخر می‌شمارد و
 * گزارش‌ها را باز می‌کند تا ببیند خطا نمی‌دهند. دو چیز را نمی‌پرسد، و
 * هر دو همان شکلِ خطایی‌اند که این هفته دو بار دیدیم — عددی که درست
 * به نظر می‌رسد و نیست:
 *
 * ۱. انبار. نان از آرد و نمک و خمیرمایه درست می‌شود و هیچ‌جا بررسی
 *    نمی‌شد که انبار دقیقاً به اندازهٔ فرمول کم شود. کیسه‌ای که کم نشود
 *    یعنی آردی که در دفتر هست و در انبار نیست.
 *
 * ۲. ترازنامه. اعدادش از جای دیگری می‌آیند تا موجودیِ حساب‌ها، و
 *    هیچ‌جا با هم مقایسه نمی‌شدند. ترازنامه‌ای که با دفتر نخواند بدتر
 *    از ترازنامه‌ای است که نباشد، چون تصمیم روی آن گرفته می‌شود.
 */
class TheMonthsMoneyAndStoreRoomAgreeTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE = 3_000;

    /**
     * What a kilo of flour costs the shop.
     *
     * Per kilo, because that is what the purchase endpoint takes. A sack
     * at 1,500,000 over a 40kg sack is 37,500, and writing the sack price
     * into a line that means the kilo price invoices the mill for sixty
     * times the lorry.
     */
    private const FLOUR_PER_KG = 37_500;

    private const OPENING = ['flour' => 20_000.0, 'salt' => 2_000.0, 'yeast_dry' => 1_000.0];

    private User $owner;

    private User $doughMaker;

    private User $chaneGir;

    private User $seller;

    private BankAccount $bank;

    private BankAccount $till;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'currency' => 'toman',
            'bread_price' => self::PRICE,
        ]);
        Money::forgetCache();

        $this->owner = $this->staff('مالک', 'admin');
        $this->doughMaker = $this->staff('خمیرگیر', 'dough_maker', 6_000_000);
        $this->chaneGir = $this->staff('چانه‌گیر', 'chane_gir', 7_000_000);
        $this->seller = $this->staff('فروشنده', 'seller', 5_000_000);

        $this->bank = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 300_000_000,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->till = BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 0,
            'is_cash_box' => true,
            'is_active' => true,
        ]);

        foreach (self::OPENING as $item => $kg) {
            $this->as($this->owner)->postJson('/api/v1/inventory/movements', [
                'item' => $item,
                'direction' => 'in',
                'quantity' => $kg,
            ])->assertCreated();
        }
    }

    private function staff(string $name, string $role, int $salary = 0): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'is_active' => true,
            'monthly_salary' => $salary,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function as(User $user): self
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /** A lorry at the door. Part paid, the rest on the mill's account. */
    private function buyFlour(int $bags = 40, float $paid = 0): array
    {
        return $this->as($this->owner)->postJson('/api/v1/purchases', [
            'supplier_name' => 'آسیاب مرکزی',
            'paid_amount' => $paid,
            'bank_account_id' => $paid > 0 ? $this->bank->id : null,
            'items' => [
                ['item' => 'flour', 'bags' => $bags, 'unit_price' => self::FLOUR_PER_KG],
            ],
        ])->assertCreated()->json('data');
    }

    private function stock(string $key): float
    {
        InventoryItem::forgetBalances();

        return round((float) InventoryItem::ofKey($key)->balance, 3);
    }

    private function formula(): DoughFormula
    {
        return DoughFormula::fromBakery();
    }

    /** One morning: kneaded, shaped, and sold out. */
    private function bakeAndSell(int $bags = 10, float $sprayKg = 3, bool $again = false): ChaneEntry
    {
        $dough = $this->as($this->doughMaker)->postJson('/api/v1/dough-entries', [
            'bag_count' => $bags,
            'yeast_type' => 'dry',
            'force' => $again,
        ])->assertCreated()->json('data.entry');

        $entry = $this->as($this->chaneGir)->postJson('/api/v1/chane-entries', [
            'dough_entry_id' => $dough['id'],
            'chane_count' => 150,
            'spray_flour_kg' => $sprayKg,
            'force' => $again,
        ])->assertCreated()->json('data.entry');

        $batch = ChaneEntry::findOrFail($entry['id']);

        $this->as($this->seller)->postJson('/api/v1/sales', [
            'chane_entry_id' => $batch->id,
            'payments' => [
                ['payment_type' => 'cash', 'bread_count' => 100, 'amount' => 100 * self::PRICE],
                ['payment_type' => 'card', 'bread_count' => 50, 'amount' => 50 * self::PRICE],
            ],
        ])->assertCreated();

        return $batch;
    }

    // --------------------------------------------------------- گردش انبار

    public function test_a_batch_takes_exactly_what_the_formula_says_out_of_the_store_room(): void
    {
        $formula = $this->formula();
        $bags = 10;

        $this->bakeAndSell($bags, sprayKg: 3);

        // The spray flour is shaped on top of what the dough drank, so the
        // flour falls by both. A shop that counted only one of them would
        // still look plausible — it is the other one that goes missing.
        $this->assertEqualsWithDelta(
            self::OPENING['flour'] - $formula->flourKg($bags) - 3,
            $this->stock('flour'),
            0.001,
        );

        $this->assertEqualsWithDelta(
            self::OPENING['salt'] - $formula->saltKg($bags),
            $this->stock('salt'),
            0.001,
        );

        $this->assertEqualsWithDelta(
            self::OPENING['yeast_dry'] - $formula->yeastKg($bags),
            $this->stock('yeast_dry'),
            0.001,
        );
    }

    public function test_every_sack_that_leaves_says_why(): void
    {
        $this->bakeAndSell();

        $reasons = InventoryItem::ofKey('flour')->movements()
            ->where('direction', 'out')
            ->pluck('reason')
            ->unique()
            ->values()
            ->all();

        // «آرد کجا رفت» is the question the store room has to be able to
        // answer, and it cannot answer it from a column of nulls.
        sort($reasons);
        $this->assertSame(['production', 'spray'], $reasons);
    }

    public function test_a_lorry_of_flour_puts_the_sacks_back(): void
    {
        $before = $this->stock('flour');

        $this->buyFlour(paid: 30_000_000);

        $bagWeight = InventoryItem::ofKey('flour')->bagWeightKg();

        $this->assertGreaterThan(0, $bagWeight, 'وزن کیسهٔ آرد تعریف نشده.');
        $this->assertEqualsWithDelta($before + 40 * $bagWeight, $this->stock('flour'), 0.001);
    }

    public function test_three_days_of_baking_and_one_delivery_still_add_up(): void
    {
        $formula = $this->formula();

        $this->buyFlour();

        $bagWeight = InventoryItem::ofKey('flour')->bagWeightKg();

        foreach (range(1, 3) as $day) {
            $this->bakeAndSell(10, sprayKg: 3, again: $day > 1);
        }

        // Opening, plus what arrived, less three days of dough and three
        // days of spray. Written out rather than accumulated in a loop, so
        // the expectation cannot drift with the code it is checking.
        $this->assertEqualsWithDelta(
            self::OPENING['flour'] + 40 * $bagWeight - 3 * $formula->flourKg(10) - 3 * 3,
            $this->stock('flour'),
            0.001,
        );

        $this->assertEqualsWithDelta(
            self::OPENING['salt'] - 3 * $formula->saltKg(10),
            $this->stock('salt'),
            0.001,
        );
    }

    // ---------------------------------------------------------- گردش مالی

    public function test_the_balance_sheet_reads_the_same_bank_balance_the_accounts_do(): void
    {
        $this->bakeAndSell();
        $this->as($this->owner)
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle", [])
            ->assertOk();

        $sheet = collect(BalanceSheet::build()['assets']);
        $bank = $sheet->firstWhere('key', 'bank');

        $real = round((float) $this->bank->fresh()->balance + (float) $this->till->fresh()->balance, 2);

        $this->assertNotNull($bank);
        $this->assertEqualsWithDelta($real, (float) $bank['amount'], 0.01);

        // And it is not zero — a sheet that agrees with the ledger by both
        // being empty has not been tested at all.
        $this->assertGreaterThan(0, $real);
    }

    public function test_the_sheet_carries_the_advances_the_staff_still_owe(): void
    {
        $this->as($this->owner)->postJson('/api/v1/staff-advances', [
            'user_id' => $this->doughMaker->id,
            'amount' => 2_000_000,
        ])->assertCreated();

        $sheet = collect(BalanceSheet::build()['assets']);

        $this->assertEqualsWithDelta(
            2_000_000,
            (float) $sheet->firstWhere('key', 'staff_advances')['amount'],
            0.01,
        );

        // Paid back out of the wage, so it stops being money owed to the
        // shop. Carrying it after the payslip would count it twice: once
        // as an asset here and once as pay the shop no longer has.
        $this->as($this->owner)->postJson('/api/v1/salaries', [
            'user_id' => $this->doughMaker->id,
            'period_start' => '1405/05/01',
            'base_amount' => 6_000_000,
            'paid_on' => '1405/05/31',
        ])->assertCreated();

        $sheet = collect(BalanceSheet::build()['assets']);

        $this->assertSame(0.0, StaffAdvance::outstandingFor($this->doughMaker->id));

        // The line is gone rather than showing zero. A sheet that lists
        // «علی‌الحساب کارکنان: ۰» invites the question of whether nobody
        // owes anything or nobody has looked.
        $this->assertNull($sheet->firstWhere('key', 'staff_advances'));
    }

    public function test_cash_a_seller_still_holds_is_on_the_sheet_and_leaves_it_when_handed_over(): void
    {
        $this->bakeAndSell();

        $held = collect(BalanceSheet::build()['assets'])
            ->firstWhere('key', 'seller_holdings')['amount'];

        // 100 loaves for cash, still in his pocket. It is the shop's money
        // and the sheet has to say so, or a month's takings sit nowhere.
        $this->assertEqualsWithDelta(100 * self::PRICE, (float) $held, 0.01);

        $this->as($this->owner)
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle", [])
            ->assertOk();

        // Now it is in the drawer, and the drawer is counted under the
        // bank line. Counting it in both places would invent a month's
        // takings out of nothing, so the line leaves the sheet entirely.
        $this->assertNull(
            collect(BalanceSheet::build()['assets'])->firstWhere('key', 'seller_holdings'),
        );
        $this->assertEqualsWithDelta(100 * self::PRICE, (float) $this->till->fresh()->balance, 0.01);
    }

    public function test_the_store_room_is_worth_something_on_the_sheet(): void
    {
        $this->buyFlour();

        $stock = collect(BalanceSheet::build()['assets'])->firstWhere('key', 'stock');

        // Flour in the store room is money the shop has already spent and
        // has not eaten yet. A sheet that valued it at nothing would show
        // a shop poorer than it is every time a lorry arrives.
        $this->assertNotNull($stock);
        $this->assertGreaterThan(0, (float) $stock['amount']);
    }

    // ------------------------------------------- طلب‌ها و بدهی‌ها

    /**
     * The two sides of the sheet that nothing compared against the records.
     *
     * The asset lines above are the ones a good month makes bigger. These
     * are the ones a bad month makes bigger, and they are exactly where
     * this shop is exposed — flour out with partners, an unpaid invoice at
     * the mill, bread sold on credit. A sheet that understates them shows
     * an owner richer than he is, which is the more expensive direction to
     * be wrong in.
     */
    public function test_bread_sold_on_credit_is_money_owed_to_the_shop(): void
    {
        $customer = Customer::create(['name' => 'مدرسه شهید بهشتی', 'type' => 'school']);

        $dough = $this->as($this->doughMaker)->postJson('/api/v1/dough-entries', [
            'bag_count' => 10,
            'yeast_type' => 'dry',
        ])->assertCreated()->json('data.entry');

        $batch = $this->as($this->chaneGir)->postJson('/api/v1/chane-entries', [
            'dough_entry_id' => $dough['id'],
            'chane_count' => 100,
            'spray_flour_kg' => 3,
        ])->assertCreated()->json('data.entry');

        $this->as($this->seller)->postJson('/api/v1/sales', [
            'chane_entry_id' => $batch['id'],
            'payments' => [[
                'payment_type' => 'credit',
                'bread_count' => 100,
                'amount' => 100 * self::PRICE,
                'customer_id' => $customer->id,
            ]],
        ])->assertCreated();

        $debt = collect(BalanceSheet::build()['assets'])->firstWhere('key', 'customer_debt');

        // Nothing reached any account — that is the point of نسیه. But the
        // shop is not poorer by a batch either, and a sheet that showed
        // neither the money nor the debt would say exactly that.
        $this->assertNotNull($debt, 'نسیه روی ترازنامه نیست.');
        $this->assertEqualsWithDelta(100 * self::PRICE, (float) $debt['amount'], 0.01);
        $this->assertSame(0.0, round((float) $this->till->fresh()->balance, 2));
    }

    public function test_flour_lent_to_a_partner_is_still_the_shops_flour(): void
    {
        // Consignment is valued at what flour last cost. A shop that has
        // never recorded a purchase has no price to value it at, so the
        // lorry comes first — which is also the order it happens in.
        $this->buyFlour();

        $this->as($this->owner)->postJson('/api/v1/consignment-flour', [
            'partner_name' => 'نانوایی هیدوچ',
            'direction' => 'lent',
            'bags' => 29,
        ])->assertCreated();

        $lent = collect(BalanceSheet::build()['assets'])->firstWhere('key', 'consignment_due');

        // It left the store room but it did not stop being ours. Twenty-nine
        // sacks with a partner is a real sum, and the shop has more than
        // fifty out at once.
        $this->assertNotNull($lent, 'آرد امانی روی ترازنامه نیست.');
        $this->assertGreaterThan(0, (float) $lent['amount']);
    }

    public function test_flour_borrowed_from_a_partner_is_owed_back(): void
    {
        $this->buyFlour();

        $this->as($this->owner)->postJson('/api/v1/consignment-flour', [
            'partner_name' => 'نانوایی پدگان',
            'direction' => 'borrowed',
            'bags' => 20,
        ])->assertCreated();

        $owed = collect(BalanceSheet::build()['liabilities'])->firstWhere('key', 'consignment_owed');

        // «در انبار هست ولی باید برگردد». Borrowed flour that only ever
        // showed up as stock would make the shop look twenty sacks richer
        // than it is.
        $this->assertNotNull($owed, 'آرد امانیِ گرفته‌شده روی ترازنامه نیست.');
        $this->assertGreaterThan(0, (float) $owed['amount']);
    }

    public function test_an_unpaid_invoice_at_the_mill_is_a_debt(): void
    {
        // The rate is per kilo, not per sack — a sack of flour at
        // 1,500,000 is 37,500 a kilo over a 40kg sack, and a test that
        // wrote the sack price here would invoice the mill for sixty times
        // what it charged.
        $purchase = $this->buyFlour(bags: 40, paid: 30_000_000);

        $invoiced = 40 * InventoryItem::ofKey('flour')->bagWeightKg() * self::FLOUR_PER_KG;

        $debt = collect(BalanceSheet::build()['liabilities'])->firstWhere('key', 'supplier_debt');

        $this->assertNotNull($debt, 'بدهی به آسیاب روی ترازنامه نیست.');
        $this->assertEqualsWithDelta($invoiced - 30_000_000, (float) $debt['amount'], 0.01);

        // Paying the rest closes it, and the line leaves the sheet.
        $this->as($this->owner)->postJson('/api/v1/supplier-payments', [
            'supplier_id' => $purchase['supplier_id'],
            'purchase_id' => $purchase['id'],
            'amount' => $invoiced - 30_000_000,
            'bank_account_id' => $this->bank->id,
        ])->assertCreated();

        $this->assertNull(
            collect(BalanceSheet::build()['liabilities'])->firstWhere('key', 'supplier_debt'),
        );
    }

    public function test_a_wage_written_but_not_handed_over_is_a_debt_not_a_payment(): void
    {
        $this->as($this->owner)->postJson('/api/v1/salaries', [
            'user_id' => $this->chaneGir->id,
            'period_start' => '1405/05/01',
            'base_amount' => 7_000_000,
            'paid_on' => null,
        ])->assertCreated();

        $before = round((float) $this->bank->fresh()->balance, 2);
        $owed = collect(BalanceSheet::build()['liabilities'])->firstWhere('key', 'unpaid_salaries');

        // Owed is not paid: the bank still holds it and the sheet says it
        // is spoken for. Showing it as neither would be a shop that thinks
        // it has a wage it has already promised.
        $this->assertNotNull($owed, 'حقوق پرداخت‌نشده روی ترازنامه نیست.');
        $this->assertEqualsWithDelta(7_000_000, (float) $owed['amount'], 0.01);
        $this->assertEqualsWithDelta(300_000_000, $before, 0.01);
    }

    public function test_consignment_flour_a_shop_cannot_price_is_shown_as_nothing(): void
    {
        // Pinned rather than approved of.
        //
        // Consignment is valued at what flour last cost, so a shop that
        // has never recorded a purchase values fifty sacks at zero — and
        // says nothing about it. The stock line in the same sheet handles
        // the same problem out loud: it keeps its row and names the goods
        // it could not price.
        //
        // Every real shop has bought flour, so this is narrow. It is here
        // so that the day somebody widens it, this test is what tells
        // them the two lines disagree about how to say «نمی‌دانم».
        $this->as($this->owner)->postJson('/api/v1/consignment-flour', [
            'partner_name' => 'نانوایی کنت',
            'direction' => 'lent',
            'bags' => 8,
        ])->assertCreated();

        $sheet = BalanceSheet::build();

        $this->assertNull(collect($sheet['assets'])->firstWhere('key', 'consignment_due'));

        // The stock line, by contrast, stays and says which goods it could
        // not put a figure on.
        $stock = collect($sheet['assets'])->firstWhere('key', 'stock');

        $this->assertNotNull($stock);
        $this->assertStringContainsString('آرد', $stock['note'] ?? '');
    }
}
