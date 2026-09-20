<?php

namespace Tests\Feature;

use App\Filament\Resources\LoanResource\Pages\EditLoan;
use App\Filament\Resources\LoanResource\RelationManagers\PaymentsRelationManager;
use App\Models\BankAccount;
use App\Models\Loan;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * فرمِ قسط، حساب را پیش از ذخیره می‌پرسد.
 *
 * این فرم هر دو نیمهٔ اشتباهی را داشت که مساعده و حقوق را به حساب غلط
 * فرستاد:
 *
 * زیر فیلد نوشته بود «خالی یعنی از صندوق» و گزینهٔ خالی‌اش «پرداخت
 * نقدی» نام داشت — در حالی که خالی روی هیچ حسابی نمی‌نشست. وام کوچک
 * می‌شد، هیچ موجودی‌ای پایین نمی‌آمد، و نانوایی بابتِ پرداختِ بدهی‌اش
 * پولدارتر به نظر می‌رسید.
 *
 * و پیش‌فرضش defaultAccount() بود، همان تابعی که می‌تواند خودِ صندوق
 * باشد.
 *
 * مالک گفت «حساب سفید» — و هم گفت که گاهی نقدی هم می‌دهد. پس فیلد
 * پُرشده باز می‌شود و اجباری است: نقدی‌بودن چیزی است که با انتخابِ
 * صندوق گفته می‌شود، نه با خالی گذاشتن.
 */
class TheInstalmentFormNamesTheAccountTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 0,
            'is_active' => true,
            'is_cash_box' => true,
        ]);

        $this->bank = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 100_000_000,
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->loan = Loan::create([
            'title' => 'وام بانک صادرات',
            'lender' => 'بانک صادرات',
            'principal' => 500_000_000,
            'instalment_amount' => 4_000_000,
            'instalment_count' => 36,
            'first_due_on' => now()->subMonth(),
        ]);

        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole('admin');

        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function payments()
    {
        return Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $this->loan,
            'pageClass' => EditLoan::class,
        ]);
    }

    public function test_the_form_opens_with_the_shops_account_already_chosen(): void
    {
        $this->payments()
            ->mountTableAction('create')
            ->assertTableActionDataSet(['bank_account_id' => $this->bank->id]);
    }

    public function test_it_is_never_the_drawer_that_is_offered(): void
    {
        // The whole of the mistake in one assertion.
        $till = BankAccount::cashBox();

        $chosen = $this->payments()
            ->mountTableAction('create')
            ->get('mountedTableActionsData.0.bank_account_id');

        $this->assertNotNull($chosen);
        $this->assertNotSame($till->id, $chosen);
    }

    public function test_an_instalment_cannot_be_saved_without_naming_an_account(): void
    {
        // Paying in notes out of the drawer is a real answer and stays
        // one — it is said by picking the till, not by leaving the field
        // empty. An empty field is a decision made on somebody's behalf.
        $this->payments()
            ->callTableAction('create', data: [
                'amount' => 4_000_000,
                'paid_on' => now()->toDateString(),
                'bank_account_id' => null,
            ])
            ->assertHasTableActionErrors(['bank_account_id']);

        $this->assertSame(0, $this->loan->payments()->count());
    }

    public function test_an_instalment_really_paid_in_cash_can_still_be_said(): void
    {
        $till = BankAccount::cashBox();

        $this->payments()
            ->callTableAction('create', data: [
                'amount' => 4_000_000,
                'paid_on' => now()->toDateString(),
                'bank_account_id' => $till->id,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertEqualsWithDelta(-4_000_000, (float) $till->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(100_000_000, (float) $this->bank->fresh()->balance, 0.01);
    }
}
