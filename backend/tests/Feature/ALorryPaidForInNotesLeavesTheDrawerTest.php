<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\Purchase;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * آردی که نقد پایش پول داده شد، از کشو بیرون می‌رود.
 *
 * تا امروز نمی‌رفت. تریلی می‌آمد، ۳۰ میلیون نقد داده می‌شد، بدهیِ
 * آسیاب کم می‌شد — و هیچ حسابی تکان نمی‌خورد. نه صندوق، نه بانک.
 * نانوایی به اندازهٔ قیمتِ چهل کیسه آرد پولدارتر به نظر می‌رسید.
 *
 * پیش از اصلاح با اجرا ثابت شد: صفر ثبت بانکی، هر دو موجودی دست‌نخورده.
 *
 * و جالب‌ترین قسمتش: بالای همان تابع نوشته بود «همان قاعدهٔ هزینه» —
 * در حالی که قاعدهٔ هزینه ماه‌ها پیش اصلاح شده بود. جمله‌ای که قرار
 * بود این دو را هم‌قدم نگه دارد، همان چیزی بود که تفاوتشان را پنهان
 * کرد.
 */
class ALorryPaidForInNotesLeavesTheDrawerTest extends TestCase
{
    use RefreshDatabase;

    private const PER_KG = 37_500;

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
            'is_default' => true,
        ]);

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');
    }

    private function lorry(array $overrides = []): array
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson('/api/v1/purchases', array_merge([
            'supplier_name' => 'آسیاب مرکزی',
            'paid_amount' => 30_000_000,
            'items' => [['item' => 'flour', 'bags' => 40, 'unit_price' => self::PER_KG]],
        ], $overrides))->assertCreated()->json('data');
    }

    private function till(): float
    {
        return round((float) $this->till->fresh()->balance, 2);
    }

    private function bank(): float
    {
        return round((float) $this->bank->fresh()->balance, 2);
    }

    public function test_paying_in_notes_takes_it_out_of_the_drawer(): void
    {
        $this->lorry(['paid_in_cash' => true]);

        $this->assertEqualsWithDelta(50_000_000 - 30_000_000, $this->till(), 0.01);
        $this->assertEqualsWithDelta(100_000_000, $this->bank(), 0.01);
    }

    public function test_it_is_the_same_answer_the_expense_screen_gives(): void
    {
        // The two used to disagree while a comment said they matched. A
        // sack of flour and a can of diesel paid for the same way out of
        // the same drawer have to land the same way.
        $this->lorry(['paid_in_cash' => true]);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/expenses', [
            'category' => 'fuel',
            'title' => 'گازوئیل',
            'amount' => 1_000_000,
            'paid_in_cash' => true,
        ])->assertCreated();

        $this->assertEqualsWithDelta(50_000_000 - 31_000_000, $this->till(), 0.01);
    }

    public function test_saying_nothing_pays_from_the_bank(): void
    {
        $this->lorry();

        $this->assertEqualsWithDelta(100_000_000 - 30_000_000, $this->bank(), 0.01);
        $this->assertEqualsWithDelta(50_000_000, $this->till(), 0.01);
    }

    public function test_it_is_never_the_drawer_that_is_assumed(): void
    {
        // The shop's «پیش‌فرض» tick can sit on the drawer. A lorry paid
        // from the bank booked as notes leaves the till reading low by
        // the price of a lorry — which is how the wage form came to
        // promise the drawer.
        $this->bank->update(['is_default' => false]);
        $this->till->update(['is_default' => true]);

        $this->lorry();

        $this->assertEqualsWithDelta(50_000_000, $this->till(), 0.01);
        $this->assertEqualsWithDelta(100_000_000 - 30_000_000, $this->bank(), 0.01);
    }

    public function test_an_invoice_paid_nothing_at_the_door_moves_nothing(): void
    {
        // All of it on the mill's account. Nothing changed hands, so
        // nothing should leave any account.
        $this->lorry(['paid_amount' => 0]);

        $this->assertEqualsWithDelta(50_000_000, $this->till(), 0.01);
        $this->assertEqualsWithDelta(100_000_000, $this->bank(), 0.01);
        $this->assertSame(0, Purchase::first()->bankTransactions()->count());
    }

    public function test_naming_an_account_still_wins(): void
    {
        $this->lorry(['bank_account_id' => $this->till->id]);

        $this->assertEqualsWithDelta(50_000_000 - 30_000_000, $this->till(), 0.01);
        $this->assertEqualsWithDelta(100_000_000, $this->bank(), 0.01);
    }
}
