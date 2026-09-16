<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حقوق از حسابی کم می‌شود که از آن پرداخت شده.
 *
 * همان غلطِ مساعده، یک روز دیرتر پیدا شد. خالی‌گذاشتنِ حساب روی فیش
 * حقوقی می‌رفت روی صندوق، چون فرمِ پنل نوشته بود «خالی بگذارید اگر از
 * صندوق پرداخت شده» و همان باور شد.
 *
 * تنها راهِ فهمیدنش پرسیدن بود، و جواب این بود: «حقوق و مزایا حساب
 * سفید». کد از روی هیچ‌چیزِ درونِ خودش نمی‌توانست این را دربیاورد —
 * تنها کسی که می‌داند، کسی است که اسکناس را داده.
 */
class AWageLeavesTheAccountItWasPaidFromTest extends TestCase
{
    use RefreshDatabase;

    private User $baker;

    private BankAccount $till;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $this->baker = User::factory()->create(['is_active' => true]);
        $this->baker->assignRole('shater');

        $this->till = BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 5_000_000,
            'is_active' => true,
            'is_cash_box' => true,
        ]);

        $this->bank = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 5_000_000,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function wage(float $amount, ?int $accountId = null, $paidOn = 'now'): SalaryPayment
    {
        return SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => now()->startOfMonth(),
            'base_amount' => $amount,
            'paid_on' => $paidOn === 'now' ? now() : $paidOn,
            'bank_account_id' => $accountId,
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

    public function test_a_wage_with_no_account_named_comes_out_of_the_bank(): void
    {
        $this->wage(1_000_000);

        $this->assertEqualsWithDelta(4_000_000, $this->bankBalance(), 0.01);

        // و صندوق دست نمی‌خورد. همین بود که غلط بود.
        $this->assertEqualsWithDelta(5_000_000, $this->tillBalance(), 0.01);
    }

    public function test_a_wage_really_paid_from_the_drawer_still_can_be_said(): void
    {
        // پیش‌فرض عوض شد، نه امکانش.
        $this->wage(1_000_000, $this->till->id);

        $this->assertEqualsWithDelta(4_000_000, $this->tillBalance(), 0.01);
        $this->assertEqualsWithDelta(5_000_000, $this->bankBalance(), 0.01);
    }

    public function test_an_unpaid_payslip_moves_nothing(): void
    {
        // فیشی که هنوز پرداخت نشده فقط یک نوشته است.
        $this->wage(1_000_000, null, null);

        $this->assertEqualsWithDelta(5_000_000, $this->bankBalance(), 0.01);
        $this->assertEqualsWithDelta(5_000_000, $this->tillBalance(), 0.01);
    }

    public function test_it_is_recorded_as_money_going_out(): void
    {
        $wage = $this->wage(1_000_000);

        $posting = $wage->bankTransactions()->first();

        $this->assertNotNull($posting);
        $this->assertSame('out', $posting->direction);
        $this->assertSame('salary', $posting->reason);
        $this->assertEqualsWithDelta(1_000_000, (float) $posting->amount, 0.01);
    }

    public function test_a_shop_with_no_bank_writes_nothing_rather_than_guessing(): void
    {
        // صندوق جوابِ جایگزین نیست: حقوقی که هیچ‌جا ننشیند شکافی است که
        // می‌شود دنبالش گشت، ولی حقوقی که اشتباه در کشو بنشیند عددی است
        // که درست به نظر می‌رسد و نیست.
        $this->bank->delete();

        $wage = $this->wage(1_000_000);

        $this->assertCount(0, $wage->bankTransactions);
        $this->assertEqualsWithDelta(5_000_000, $this->tillBalance(), 0.01);
    }

    public function test_deleting_a_payslip_puts_the_money_back(): void
    {
        $wage = $this->wage(1_000_000);
        $wage->delete();

        $this->assertEqualsWithDelta(5_000_000, $this->bankBalance(), 0.01);
    }
}
