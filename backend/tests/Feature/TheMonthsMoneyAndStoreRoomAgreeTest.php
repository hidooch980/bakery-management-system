<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\ChaneEntry;
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

        $this->as($this->owner)->postJson('/api/v1/purchases', [
            'supplier_name' => 'آسیاب مرکزی',
            'paid_amount' => 30_000_000,
            'bank_account_id' => $this->bank->id,
            'items' => [
                ['item' => 'flour', 'bags' => 40, 'unit_price' => 1_500_000],
            ],
        ])->assertCreated();

        $bagWeight = InventoryItem::ofKey('flour')->bagWeightKg();

        $this->assertGreaterThan(0, $bagWeight, 'وزن کیسهٔ آرد تعریف نشده.');
        $this->assertEqualsWithDelta($before + 40 * $bagWeight, $this->stock('flour'), 0.001);
    }

    public function test_three_days_of_baking_and_one_delivery_still_add_up(): void
    {
        $formula = $this->formula();

        $this->as($this->owner)->postJson('/api/v1/purchases', [
            'supplier_name' => 'آسیاب مرکزی',
            'paid_amount' => 0,
            'items' => [
                ['item' => 'flour', 'bags' => 40, 'unit_price' => 1_500_000],
            ],
        ])->assertCreated();

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
        $this->as($this->owner)->postJson('/api/v1/purchases', [
            'supplier_name' => 'آسیاب مرکزی',
            'paid_amount' => 0,
            'items' => [
                ['item' => 'flour', 'bags' => 40, 'unit_price' => 1_500_000],
            ],
        ])->assertCreated();

        $stock = collect(BalanceSheet::build()['assets'])->firstWhere('key', 'stock');

        // Flour in the store room is money the shop has already spent and
        // has not eaten yet. A sheet that valued it at nothing would show
        // a shop poorer than it is every time a lorry arrives.
        $this->assertNotNull($stock);
        $this->assertGreaterThan(0, (float) $stock['amount']);
    }
}
