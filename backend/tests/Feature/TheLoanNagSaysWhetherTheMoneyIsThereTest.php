<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\Loan;
use App\Support\IssueScanner;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The overdue-instalment line answers the question it used to ask.
 *
 * It ended «مطمئن شوید موجودی حساب کافی است» — telling the owner to go and
 * look up a figure the same scan had already read. A prompt to check
 * something the shop knows is a prompt that gets put off, and the
 * instalment that gets put off is the one that earns a penalty.
 *
 * Written the day the shop's own instalment had been overdue long enough
 * to be read aloud twice: 40,000,000 rial owed against 156,136,246 in the
 * bank. The money was there the whole time. Nothing said so.
 */
class TheLoanNagSaysWhetherTheMoneyIsThereTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $till;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $this->till = BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 90_000_000,
            'is_active' => true,
            'is_cash_box' => true,
        ]);
    }

    private function bankHolding(float $amount): BankAccount
    {
        return BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => $amount,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function anOverdueLoan(float $instalment = 4_000_000): Loan
    {
        return Loan::create([
            'title' => 'وام خرید دستگاه',
            'principal' => 307_668_800,
            'instalment_amount' => $instalment,
            'instalment_count' => 77,
            'first_due_on' => now()->subMonth(),
        ]);
    }

    private function loanIssueText(): string
    {
        $issue = (new IssueScanner)->scan()
            ->first(fn ($i) => str_starts_with($i->key, 'loan-due-'));

        $this->assertNotNull($issue, 'قسط عقب‌افتاده اصلاً گزارش نشد.');

        return $issue->suggestion;
    }

    public function test_it_says_the_money_is_there_when_it_is(): void
    {
        $this->bankHolding(20_000_000);
        $this->anOverdueLoan(4_000_000);

        $this->assertStringContainsString('کفایت می‌کند', $this->loanIssueText());
    }

    public function test_it_says_how_much_is_missing_when_it_is_not(): void
    {
        $this->bankHolding(1_000_000);
        $this->anOverdueLoan(4_000_000);

        $text = $this->loanIssueText();

        $this->assertStringContainsString('کم دارید', $text);
        $this->assertStringContainsString(Money::format(3_000_000), $text);
    }

    public function test_it_never_counts_the_drawer_as_available(): void
    {
        // The instalment leaves the bank — that is the rule LoanPayment
        // itself follows. A drawer full of notes does not make a transfer
        // possible, and saying it does sends the owner to the bank to find
        // nothing there.
        $this->bankHolding(1_000_000);
        $this->anOverdueLoan(4_000_000);

        // The till holds 90,000,000 — far more than the instalment.
        $this->assertStringContainsString('کم دارید', $this->loanIssueText());
    }

    public function test_it_says_nothing_rather_than_a_wrong_figure_with_no_bank(): void
    {
        // Two banks and no default: mainBank() refuses to guess, and a
        // figure that is only one of them is worse than no figure.
        BankAccount::create(['title' => 'حساب یک', 'opening_balance' => 50_000_000, 'is_active' => true]);
        BankAccount::create(['title' => 'حساب دو', 'opening_balance' => 50_000_000, 'is_active' => true]);

        $this->anOverdueLoan(4_000_000);

        $text = $this->loanIssueText();

        $this->assertStringNotContainsString('کفایت می‌کند', $text);
        $this->assertStringNotContainsString('کم دارید', $text);
    }

    public function test_the_old_instruction_to_go_and_look_is_gone(): void
    {
        $this->bankHolding(20_000_000);
        $this->anOverdueLoan(4_000_000);

        $this->assertStringNotContainsString('مطمئن شوید موجودی', $this->loanIssueText());
    }

    public function test_what_to_do_about_the_instalment_is_still_said(): void
    {
        // The balance is the addition, not the replacement. A line that
        // only reported a balance would say nothing about the loan.
        $this->bankHolding(20_000_000);
        $this->anOverdueLoan(4_000_000);

        $this->assertStringContainsString('ثبت کنید', $this->loanIssueText());
    }
}
