<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «نقدی» یک جواب است، نه جوابِ نداده.
 *
 * هر جا مغازه پول نقد می‌داد، تیک «از صندوق پرداخت شد» یعنی هیچ حسابی
 * نامیده نشود — و «هیچ حساب» یعنی هیچ حرکتی هم ثبت نشود. اسکناس از کشو
 * بیرون می‌رفت و دفتر همچنان آن را در کشو می‌دید.
 *
 * همان اشتباه در فیش حقوقی پیدا و بسته شد، بعد در مساعده، و حالا در
 * هزینه و پرداخت به کارخانه — که پرتکرارترین‌شان است، چون دیزل و کرایه و
 * باربری همان چیزهایی‌اند که نقد داده می‌شوند.
 *
 * جهتش هم یک‌طرفه است: همه پول بیرون‌رونده‌اند، پس نبودشان کشو را بالا
 * نگه می‌داشت.
 */
class PaidFromTheDrawerLeavesTheDrawerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BankAccount $till;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->bank = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 10_000_000,
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->till = BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 10_000_000,
            'is_active' => true,
            'is_cash_box' => true,
        ]);
    }

    private function tillBalance(): float
    {
        return round((float) $this->till->fresh()->balance, 2);
    }

    private function bankBalance(): float
    {
        return round((float) $this->bank->fresh()->balance, 2);
    }

    private function expense(array $extra = [])
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/expenses', array_merge([
                'category' => 'fuel',
                'title' => 'گازوئیل',
                'amount' => 2_000_000,
            ], $extra));
    }

    private function payMill(array $extra = [])
    {
        $mill = Supplier::create(['name' => 'کارخانهٔ وحدت', 'is_active' => true]);

        return $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/supplier-payments', array_merge([
                'supplier_id' => $mill->id,
                'amount' => 3_000_000,
            ], $extra));
    }

    // ------------------------------------------------------------ هزینه

    public function test_a_cash_expense_comes_out_of_the_till(): void
    {
        $this->expense(['paid_in_cash' => true])->assertCreated();

        $this->assertEqualsWithDelta(8_000_000, $this->tillBalance(), 0.01);
        $this->assertEqualsWithDelta(10_000_000, $this->bankBalance(), 0.01);
    }

    public function test_the_cash_expense_is_tied_to_the_till_on_the_row(): void
    {
        $this->expense(['paid_in_cash' => true])->assertCreated();

        $this->assertSame($this->till->id, Expense::first()->bank_account_id);
    }

    public function test_an_expense_with_nothing_said_still_comes_off_the_bank(): void
    {
        $this->expense()->assertCreated();

        $this->assertEqualsWithDelta(8_000_000, $this->bankBalance(), 0.01);
        $this->assertEqualsWithDelta(10_000_000, $this->tillBalance(), 0.01);
    }

    public function test_a_named_account_still_wins_over_both(): void
    {
        $this->expense([
            'paid_in_cash' => true,
            'bank_account_id' => $this->bank->id,
        ])->assertCreated();

        $this->assertEqualsWithDelta(8_000_000, $this->bankBalance(), 0.01);
        $this->assertEqualsWithDelta(10_000_000, $this->tillBalance(), 0.01);
    }

    public function test_a_shop_with_no_till_records_no_movement(): void
    {
        // مغازه‌ای که کشو تعریف نکرده حسابی ندارد که این پول را به آن
        // ببندیم. ساختنش از خودمان یعنی سیستم حسابی را فرض کند که هیچ‌وقت
        // به او گفته نشده؛ صفحهٔ «مشکلات» همان مغازه را نام می‌برد.
        $this->till->update(['is_cash_box' => false]);

        $this->expense(['paid_in_cash' => true])->assertCreated();

        $this->assertNull(Expense::first()->bank_account_id);
    }

    // --------------------------------------------- پرداخت به کارخانه

    public function test_a_cash_payment_to_the_mill_comes_out_of_the_till(): void
    {
        $this->payMill(['paid_in_cash' => true])->assertCreated();

        $this->assertEqualsWithDelta(7_000_000, $this->tillBalance(), 0.01);
        $this->assertEqualsWithDelta(10_000_000, $this->bankBalance(), 0.01);
    }

    public function test_the_mill_payment_is_tied_to_the_till_on_the_row(): void
    {
        $this->payMill(['paid_in_cash' => true])->assertCreated();

        $this->assertSame($this->till->id, SupplierPayment::first()->bank_account_id);
    }

    public function test_a_mill_payment_with_nothing_said_still_comes_off_the_bank(): void
    {
        $this->payMill()->assertCreated();

        $this->assertEqualsWithDelta(7_000_000, $this->bankBalance(), 0.01);
        $this->assertEqualsWithDelta(10_000_000, $this->tillBalance(), 0.01);
    }

    public function test_a_mill_payment_to_a_named_account_ignores_the_cash_flag(): void
    {
        $this->payMill([
            'paid_in_cash' => true,
            'bank_account_id' => $this->bank->id,
        ])->assertCreated();

        $this->assertEqualsWithDelta(7_000_000, $this->bankBalance(), 0.01);
        $this->assertEqualsWithDelta(10_000_000, $this->tillBalance(), 0.01);
    }

    public function test_a_shop_with_no_till_records_no_mill_movement(): void
    {
        $this->till->update(['is_cash_box' => false]);

        $this->payMill(['paid_in_cash' => true])->assertCreated();

        $this->assertNull(SupplierPayment::first()->bank_account_id);
    }
}
