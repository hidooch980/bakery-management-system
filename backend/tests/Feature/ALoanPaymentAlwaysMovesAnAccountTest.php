<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A repayment leaves an account, and correcting one must not lose that.
 *
 * PostsToBankAccount rebuilds on save: it clears every posting tagged to
 * the record, then writes a fresh one *if the record names an account*. A
 * payment with no `bank_account_id` therefore has its withdrawal quietly
 * deleted the moment anything about it is edited — and the balance goes
 * **up**.
 *
 * That happened on this shop's data on 2026-08-17. Correcting the 8 Mordad
 * instalment from 5,000,000 to 50,000,000 Rial should have taken another
 * 45,000,000 off حساب سفید; it added 5,000,000 instead, because the
 * payment had been attached to an existing withdrawal by hand and never
 * carried an account of its own.
 *
 * Caught because the balance was read before and after rather than
 * assumed. No test owned a record in that state, which is why these exist.
 */
class ALoanPaymentAlwaysMovesAnAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BankAccount $account;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);

        // The seeder does not open an account, so this test opens the one
        // it needs: a real bank rather than the till, with something in it
        // to draw against.
        $this->account = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 100_000_000,
            'is_default' => true,
            'is_cash_box' => false,
            'is_active' => true,
        ]);

        $this->loan = Loan::create([
            'title' => 'وام خرید دستگاه',
            'principal' => 307_668_800,
            'instalment_amount' => 4_000_000,
            'instalment_count' => 77,
            'first_due_on' => now()->subMonth(),
        ]);
    }

    private function pay(float $amount, ?int $accountId = null): LoanPayment
    {
        return LoanPayment::create([
            'loan_id' => $this->loan->id,
            'user_id' => $this->admin->id,
            'bank_account_id' => $accountId ?? $this->account->id,
            'amount' => $amount,
            'paid_on' => now(),
        ]);
    }

    private function balance(): float
    {
        return (float) $this->account->fresh()->balance;
    }

    public function test_paying_takes_the_money_out(): void
    {
        $before = $this->balance();

        $this->pay(5_000_000);

        $this->assertEqualsWithDelta($before - 5_000_000, $this->balance(), 0.01);
    }

    public function test_correcting_the_amount_moves_the_account_by_the_difference(): void
    {
        $payment = $this->pay(500_000);
        $after = $this->balance();

        // The ten-times correction, which is what went wrong on the shop's
        // own data.
        $payment->amount = 5_000_000;
        $payment->save();

        $this->assertEqualsWithDelta($after - 4_500_000, $this->balance(), 0.01);
    }

    public function test_a_payment_with_no_account_never_had_a_posting_to_lose(): void
    {
        $before = $this->balance();

        $payment = LoanPayment::create([
            'loan_id' => $this->loan->id,
            'user_id' => $this->admin->id,
            'amount' => 500_000,
            'paid_on' => now(),
        ]);

        // Nothing moved, which is consistent — but it is also the shape
        // that bit: a payment recorded against no account is money the
        // ledger has not seen leave.
        $this->assertEqualsWithDelta($before, $this->balance(), 0.01);
        $this->assertSame(0, $this->postingsFor($payment));
    }

    public function test_naming_an_account_on_an_old_payment_writes_the_withdrawal(): void
    {
        $payment = LoanPayment::create([
            'loan_id' => $this->loan->id,
            'user_id' => $this->admin->id,
            'amount' => 5_000_000,
            'paid_on' => now(),
        ]);

        $before = $this->balance();

        // The repair: say which account it came out of, and saving does
        // the rest.
        $payment->bank_account_id = $this->account->id;
        $payment->save();

        $this->assertSame(1, $this->postingsFor($payment));
        $this->assertEqualsWithDelta($before - 5_000_000, $this->balance(), 0.01);
    }

    public function test_the_posting_is_a_withdrawal_and_says_it_is_a_loan(): void
    {
        $payment = $this->pay(5_000_000);

        $posting = BankTransaction::where('source_type', LoanPayment::class)
            ->where('source_id', $payment->id)
            ->firstOrFail();

        $this->assertSame('out', $posting->direction);
        $this->assertSame('loan', $posting->reason);
        $this->assertEqualsWithDelta(5_000_000, (float) $posting->amount, 0.01);
    }

    public function test_deleting_a_payment_gives_the_money_back(): void
    {
        $payment = $this->pay(5_000_000);
        $after = $this->balance();

        $payment->delete();

        $this->assertEqualsWithDelta($after + 5_000_000, $this->balance(), 0.01);
    }

    private function postingsFor(LoanPayment $payment): int
    {
        return BankTransaction::where('source_type', LoanPayment::class)
            ->where('source_id', $payment->id)
            ->count();
    }
}
