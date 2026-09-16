<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\SalaryPayment;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Support\IssueScanner;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * کسی دنبالِ شکاف نمی‌گشت.
 *
 * هر قاعدهٔ پرداختِ این سیستم یک‌جور تمام می‌شود: یا حساب را نام ببر،
 * یا وقتی نانوایی نگفته از کدام بانک پرداخت می‌کند، هیچ‌جا ننشان. نیمهٔ
 * دوم عمدی است و توجیهش در چهار جای این کد نوشته شده: «پرداختی که
 * هیچ‌جا ننشیند شکافی است که می‌شود دنبالش گشت، ولی پرداختی که اشتباه
 * در کشو بنشیند عددی است که درست به نظر می‌رسد و نیست.»
 *
 * ولی هیچ صفحه‌ای آن شکاف را نشان نمی‌داد. یعنی نیمهٔ اولِ جمله درست
 * بود و نیمهٔ دومش — «می‌شود دنبالش گشت» — نبود.
 */
class MoneyThatMovedNowhereIsReportedTest extends TestCase
{
    use RefreshDatabase;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $this->baker = User::factory()->create(['is_active' => true]);
        $this->baker->assignRole('shater');

        // A shop with a drawer and no bank — the state that makes every
        // payment rule fall through to «post nowhere».
        BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 0,
            'is_active' => true,
            'is_cash_box' => true,
        ]);
    }

    private function issue(): ?object
    {
        return (new IssueScanner)->scan()
            ->firstWhere('key', 'money-that-moved-nowhere');
    }

    private function advance(float $amount): StaffAdvance
    {
        return StaffAdvance::create([
            'user_id' => $this->baker->id,
            'amount' => $amount,
            'paid_on' => now(),
        ]);
    }

    public function test_a_shop_where_every_payment_landed_somewhere_is_told_nothing(): void
    {
        BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 10_000_000,
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->advance(1_000_000);

        $this->assertNull($this->issue());
    }

    public function test_an_advance_that_posted_nowhere_is_reported(): void
    {
        $this->advance(1_000_000);

        $issue = $this->issue();

        $this->assertNotNull($issue, 'شکاف گزارش نشد.');
        $this->assertSame('critical', $issue->severity);
        $this->assertEqualsWithDelta(1_000_000, $issue->magnitude, 0.01);
        $this->assertStringContainsString('مساعده', $issue->detail);
    }

    public function test_a_wage_that_posted_nowhere_is_reported(): void
    {
        SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => now()->startOfMonth(),
            'base_amount' => 9_000_000,
            'paid_on' => now(),
        ]);

        $issue = $this->issue();

        $this->assertNotNull($issue);
        $this->assertEqualsWithDelta(9_000_000, $issue->magnitude, 0.01);
        $this->assertStringContainsString('فیش حقوقی', $issue->detail);
    }

    public function test_a_payslip_not_yet_paid_is_not_a_gap(): void
    {
        // Owed is not paid. Nothing left the shop, so no account should be
        // any lighter and there is nothing to report.
        SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => now()->startOfMonth(),
            'base_amount' => 9_000_000,
            'paid_on' => null,
        ]);

        $this->assertNull($this->issue());
    }

    public function test_it_adds_up_every_kind_of_payment(): void
    {
        $this->advance(1_000_000);

        SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => now()->startOfMonth(),
            'base_amount' => 9_000_000,
            'paid_on' => now(),
        ]);

        Expense::create([
            'category' => 'fuel',
            'title' => 'گازوئیل',
            'amount' => 500_000,
            'spent_on' => now(),
        ]);

        $issue = $this->issue();

        $this->assertNotNull($issue);

        // 9,000,000 less the 1,000,000 advance it recovers, plus the
        // advance itself, plus the diesel.
        $this->assertEqualsWithDelta(9_500_000, $issue->magnitude, 0.01);
    }

    public function test_with_no_bank_it_says_to_make_one_rather_than_to_go_and_edit_rows(): void
    {
        $this->advance(1_000_000);

        $issue = $this->issue();

        // There is nowhere to point the rows at yet, so sending the owner
        // to edit them one by one would be sending him nowhere.
        $this->assertStringContainsString('پیش‌فرض', $issue->suggestion);
        $this->assertSame('/admin/bank-accounts', $issue->url);
    }

    public function test_with_a_bank_it_names_the_commands_that_move_them(): void
    {
        // The rule is corrected but a posting is only rebuilt on save, so
        // rows written under the old rule are still stranded. Simulated by
        // clearing the posting the way the old rule left it.
        $bank = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 10_000_000,
            'is_active' => true,
            'is_default' => true,
        ]);

        $advance = $this->advance(1_000_000);
        $advance->clearBankTransactions();

        $issue = $this->issue();

        $this->assertNotNull($issue);
        $this->assertStringContainsString('off-the-till', $issue->suggestion);
        $this->assertNotSame('/admin/bank-accounts', $issue->url);
        $this->assertNotNull($bank->fresh());
    }
}
