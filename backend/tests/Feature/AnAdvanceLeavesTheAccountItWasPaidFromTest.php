<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مساعده از حسابی کم می‌شود که از آن پرداخت شده.
 *
 * دو بار غلط بود. اول اصلاً هیچ حسابی سبک نمی‌شد: پول بیرون می‌رفت و
 * دفتر همچنان آن را داشت. آن درست شد و این فایل همان را نگه می‌داشت —
 * ولی روی حدسِ غلط، که خالی یعنی «از صندوق».
 *
 * حدس از خودِ فرم پنل آمده بود: «خالی بگذارید اگر از صندوق پرداخت شده».
 * مالک گفت مساعده از حساب سفید می‌رود، و او می‌داند پول از کجا بیرون
 * می‌رود — جملهٔ فرم هم با همین عوض شد، چون جمله‌ای که باعثِ حدسِ غلط
 * بوده اگر بماند نفر بعدی را هم همان‌جا می‌برد.
 *
 * نکتهٔ ماندگارش این است: «کدام حساب» را کد نمی‌تواند از روی چیزی در
 * خودش بفهمد. تنها کسی که می‌داند، کسی است که اسکناس را داده.
 */
class AnAdvanceLeavesTheAccountItWasPaidFromTest extends TestCase
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

    private function advance(float $amount, ?int $accountId = null): StaffAdvance
    {
        return StaffAdvance::create([
            'user_id' => $this->baker->id,
            'amount' => $amount,
            'paid_on' => now(),
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

    public function test_an_advance_with_no_account_named_comes_out_of_the_bank(): void
    {
        $this->advance(1_000_000);

        $this->assertEqualsWithDelta(4_000_000, $this->bankBalance(), 0.01);

        // و صندوق دست نمی‌خورد. همین بود که غلط بود.
        $this->assertEqualsWithDelta(5_000_000, $this->tillBalance(), 0.01);
    }

    public function test_an_advance_really_paid_from_the_drawer_still_can_be_said(): void
    {
        // پیش‌فرض عوض شد، نه امکانش. مساعده‌ای که واقعاً از کشو داده شده
        // همچنان با نام بردنِ صندوق ثبت می‌شود.
        $this->advance(1_000_000, $this->till->id);

        $this->assertEqualsWithDelta(4_000_000, $this->tillBalance(), 0.01);
        $this->assertEqualsWithDelta(5_000_000, $this->bankBalance(), 0.01);
    }

    public function test_it_is_recorded_as_money_going_out(): void
    {
        $advance = $this->advance(1_000_000);

        $posting = $advance->bankTransactions()->first();

        $this->assertNotNull($posting);
        $this->assertSame('out', $posting->direction);
        $this->assertSame('advance', $posting->reason);
        $this->assertEqualsWithDelta(1_000_000, (float) $posting->amount, 0.01);
    }

    public function test_a_shop_with_no_bank_writes_nothing_rather_than_guessing(): void
    {
        // بدون حساب بانکی جایی برای نوشتنش نیست، و **صندوق جوابِ جایگزین
        // نیست**: پرداختی که هیچ‌جا ننشیند شکافی است که می‌شود دنبالش
        // گشت، ولی پرداختی که اشتباه در کشو بنشیند عددی است که درست به
        // نظر می‌رسد و نیست. همان چیزی که امروز اصلاحش کردیم.
        $this->bank->delete();

        $advance = $this->advance(1_000_000);

        $this->assertCount(0, $advance->bankTransactions);
        $this->assertEqualsWithDelta(5_000_000, $this->tillBalance(), 0.01);
    }

    public function test_correcting_the_amount_moves_the_posting_with_it(): void
    {
        // مبلغ روی ردیف عوض می‌شود و ثبت بانکی از نو ساخته می‌شود، نه
        // اینکه ردیف دومی کنارش بنشیند.
        $advance = $this->advance(1_000_000);

        $advance->update(['amount' => 600_000]);

        $this->assertCount(1, $advance->fresh()->bankTransactions);
        $this->assertEqualsWithDelta(4_400_000, $this->bankBalance(), 0.01);
    }

    public function test_deleting_an_advance_puts_the_money_back(): void
    {
        $advance = $this->advance(1_000_000);
        $advance->delete();

        $this->assertEqualsWithDelta(5_000_000, $this->bankBalance(), 0.01);
    }
}
