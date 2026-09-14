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
 * مساعده‌ای که از کشو داده می‌شود، از کشو کم می‌شود.
 *
 * فرم پنل خودش می‌نویسد «خالی بگذارید اگر از صندوق پرداخت شده» — و همان
 * خالی گذاشتن، تا امروز یعنی هیچ حسابی سبک نشود. اسکناس از کشو بیرون
 * می‌رفت و دفتر همچنان آن را در کشو می‌دید.
 *
 * دقیقاً همین ایراد برای فیش حقوقی پیدا و بسته شده بود، با همین جمله که
 * «از صندوق» یک جواب است نه جوابِ نداده — و به مساعده نرسیده بود. دو مدل
 * خواهر، یک سؤال، دو جواب.
 */
class AnAdvanceOutOfTheDrawerLeavesTheDrawerTest extends TestCase
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

    public function test_an_advance_with_no_account_named_comes_out_of_the_till(): void
    {
        $this->advance(1_000_000);

        $this->assertEqualsWithDelta(4_000_000, $this->tillBalance(), 0.01);
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

    public function test_an_advance_from_a_named_account_still_comes_out_of_that_one(): void
    {
        $this->advance(1_000_000, $this->bank->id);

        $this->assertEqualsWithDelta(4_000_000, (float) $this->bank->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(5_000_000, $this->tillBalance(), 0.01);
    }

    public function test_a_shop_with_no_till_loses_nothing_but_says_nothing_either(): void
    {
        // بدون صندوق جایی برای نوشتنش نیست. ساختن حساب از خودمان یعنی
        // سیستم تصمیم بگیرد مغازه حسابی دارد که هیچ‌وقت به او گفته نشده؛
        // صفحهٔ «مشکلات» همین مغازه را از قبل نام می‌برد.
        $this->till->update(['is_cash_box' => false]);

        $advance = $this->advance(1_000_000);

        $this->assertCount(0, $advance->bankTransactions);
    }

    public function test_correcting_the_amount_moves_the_posting_with_it(): void
    {
        // مبلغ روی ردیف عوض می‌شود و ثبت بانکی از نو ساخته می‌شود، نه
        // اینکه ردیف دومی کنارش بنشیند.
        $advance = $this->advance(1_000_000);

        $advance->update(['amount' => 600_000]);

        $this->assertCount(1, $advance->fresh()->bankTransactions);
        $this->assertEqualsWithDelta(4_400_000, $this->tillBalance(), 0.01);
    }

    public function test_deleting_an_advance_puts_the_money_back(): void
    {
        $advance = $this->advance(1_000_000);
        $advance->delete();

        $this->assertEqualsWithDelta(5_000_000, $this->tillBalance(), 0.01);
    }
}
