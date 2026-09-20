<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * پولی که نقدی نبوده، از کشو بیرون نمی‌رود.
 *
 * «نقدی نبود» یعنی بانک. ولی هزینه و پرداخت به تأمین‌کننده هر دو
 * defaultAccount() را می‌پرسیدند، و آن تابع دو جورْ صندوق را برمی‌گرداند:
 *
 *   ۱. تیکِ «پیش‌فرض» روی خودِ صندوق نشسته باشد
 *   ۲. هیچ حسابی پیش‌فرض نباشد — آن‌وقت اولین حسابِ فعال، که تقریباً
 *      همیشه صندوق است چون اول از همه ساخته می‌شود
 *
 * پیش از اصلاح با اجرا ثابت شد: یک قبض گازوئیلِ کارتی و یک حواله به
 * آسیاب، هر دو از کشو بیرون رفتند و بانک دست‌نخورده ماند —
 *
 *     صندوق: ۴۴,۰۰۰,۰۰۰   (باید ۵۰,۰۰۰,۰۰۰ می‌ماند)
 *     بانک:  ۱۰۰,۰۰۰,۰۰۰  (باید ۹۴,۰۰۰,۰۰۰ می‌شد)
 *
 * ششمین جای همین اشتباه، بعد از مساعده، فیش حقوقی، قسط وام، تسویهٔ
 * ناقص و خریدِ تریلی. تنها راه پیدا شدنش شمردنِ اسکناس‌های کشو بود.
 */
class TheDrawerIsNotAssumedForMoneyGoingOutTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private BankAccount $till;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        // Built first, as a real shop builds it — which is what made the
        // «no default at all» half of this bug land on the drawer too.
        $this->till = BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 50_000_000,
            'is_active' => true,
            'is_cash_box' => true,
        ]);

        $this->bank = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 100_000_000,
            'is_active' => true,
        ]);

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');

        Sanctum::actingAs($this->owner);
    }

    private function diesel(array $overrides = []): void
    {
        $this->postJson('/api/v1/expenses', array_merge([
            'category' => 'fuel',
            'title' => 'گازوئیل',
            'amount' => 1_000_000,
        ], $overrides))->assertCreated();
    }

    private function payTheMill(array $overrides = []): void
    {
        $mill = Supplier::firstOrCreate(['name' => 'آسیاب مرکزی']);

        $this->postJson('/api/v1/supplier-payments', array_merge([
            'supplier_id' => $mill->id,
            'amount' => 5_000_000,
        ], $overrides))->assertCreated();
    }

    private function till(): float
    {
        return round((float) $this->till->fresh()->balance, 2);
    }

    private function bank(): float
    {
        return round((float) $this->bank->fresh()->balance, 2);
    }

    // ---------------------------------------- no default flagged at all

    public function test_an_expense_with_no_default_flagged_still_leaves_the_bank(): void
    {
        $this->diesel();

        $this->assertEqualsWithDelta(50_000_000, $this->till(), 0.01);
        $this->assertEqualsWithDelta(100_000_000 - 1_000_000, $this->bank(), 0.01);
    }

    public function test_a_transfer_to_the_mill_with_no_default_flagged_leaves_the_bank(): void
    {
        $this->payTheMill();

        $this->assertEqualsWithDelta(50_000_000, $this->till(), 0.01);
        $this->assertEqualsWithDelta(100_000_000 - 5_000_000, $this->bank(), 0.01);
    }

    // ---------------------------------------- the default IS the drawer

    public function test_an_expense_is_not_booked_to_the_drawer_because_it_is_the_default(): void
    {
        $this->till->update(['is_default' => true]);

        $this->diesel();

        $this->assertEqualsWithDelta(50_000_000, $this->till(), 0.01);
        $this->assertEqualsWithDelta(100_000_000 - 1_000_000, $this->bank(), 0.01);
    }

    public function test_a_transfer_is_not_booked_to_the_drawer_because_it_is_the_default(): void
    {
        $this->till->update(['is_default' => true]);

        $this->payTheMill();

        $this->assertEqualsWithDelta(50_000_000, $this->till(), 0.01);
        $this->assertEqualsWithDelta(100_000_000 - 5_000_000, $this->bank(), 0.01);
    }

    // ---------------------------------------- what still has to work

    public function test_saying_it_was_cash_still_means_the_drawer(): void
    {
        $this->diesel(['paid_in_cash' => true]);
        $this->payTheMill(['paid_in_cash' => true]);

        $this->assertEqualsWithDelta(50_000_000 - 6_000_000, $this->till(), 0.01);
        $this->assertEqualsWithDelta(100_000_000, $this->bank(), 0.01);
    }

    public function test_naming_an_account_still_wins_over_everything(): void
    {
        $this->diesel(['bank_account_id' => $this->till->id]);

        $this->assertEqualsWithDelta(50_000_000 - 1_000_000, $this->till(), 0.01);
        $this->assertEqualsWithDelta(100_000_000, $this->bank(), 0.01);
    }

    public function test_the_shops_own_bank_is_used_when_it_is_flagged_default(): void
    {
        $this->bank->update(['is_default' => true]);

        $this->diesel();
        $this->payTheMill();

        $this->assertEqualsWithDelta(50_000_000, $this->till(), 0.01);
        $this->assertEqualsWithDelta(100_000_000 - 6_000_000, $this->bank(), 0.01);
    }

    public function test_a_gap_rather_than_a_guess_when_two_banks_and_no_default(): void
    {
        // Picking the lower id would be a guess about which account paid.
        // A gap the issues page names is the better answer — and the same
        // rule the lorry and the wage slip already follow.
        BankAccount::create([
            'title' => 'حساب دوم',
            'opening_balance' => 10_000_000,
            'is_active' => true,
        ]);

        $this->diesel();

        $this->assertEqualsWithDelta(50_000_000, $this->till(), 0.01);
        $this->assertEqualsWithDelta(100_000_000, $this->bank(), 0.01);
    }
}
