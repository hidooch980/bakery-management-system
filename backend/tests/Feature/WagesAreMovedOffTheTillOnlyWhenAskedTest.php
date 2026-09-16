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
 * Putting right the payslips that a wrong guess filed against the drawer.
 *
 * The same shape as the advances, and for the same reason — the panel
 * form's sentence was believed instead of the owner being asked. He was
 * asked: «حقوق و مزایا حساب سفید».
 *
 * Two things this has to get right, and they pull opposite ways. It must
 * move the ones that were guessed at, and it must not touch a wage
 * somebody deliberately marked as paid from the drawer: that one is not a
 * mistake, and «correcting» it would be the same error again in the other
 * direction.
 *
 * And it shows before it acts. A wage is the largest single thing this
 * shop pays out, and this moves real money between real accounts.
 */
class WagesAreMovedOffTheTillOnlyWhenAskedTest extends TestCase
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
            'opening_balance' => 10_000_000,
            'is_active' => true,
            'is_cash_box' => true,
        ]);

        $this->bank = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 10_000_000,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function wage(float $amount, ?int $accountId = null): SalaryPayment
    {
        return SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => now()->startOfMonth(),
            'base_amount' => $amount,
            'paid_on' => now(),
            'bank_account_id' => $accountId,
        ]);
    }

    /** A payslip as the old rule left it: no account named, posted to the till. */
    private function strandedOnTheTill(float $amount): SalaryPayment
    {
        $wage = $this->wage($amount);

        // What the old fallback wrote. Done by hand because the rule that
        // produced it is gone.
        $wage->bankTransactions()->update(['bank_account_id' => $this->till->id]);
        BankAccount::forgetLedgerTotals();

        return $wage;
    }

    private function balances(): array
    {
        return [
            round((float) $this->till->fresh()->balance, 2),
            round((float) $this->bank->fresh()->balance, 2),
        ];
    }

    public function test_it_shows_what_it_would_move_and_moves_nothing(): void
    {
        $this->strandedOnTheTill(1_000_000);

        $this->artisan('salaries:off-the-till')
            ->expectsOutputToContain('چیزی جابه‌جا نشد')
            ->assertSuccessful();

        $this->assertSame([9_000_000.0, 10_000_000.0], $this->balances());
    }

    public function test_with_apply_the_money_moves_to_the_bank(): void
    {
        $this->strandedOnTheTill(1_000_000);

        $this->artisan('salaries:off-the-till', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame([10_000_000.0, 9_000_000.0], $this->balances());
    }

    public function test_a_wage_really_paid_from_the_drawer_is_left_alone(): void
    {
        // Named the till on its own row, so it is a decision and not the
        // guess. Moving it would be the same mistake the other way round.
        $this->wage(400_000, $this->till->id);

        $this->artisan('salaries:off-the-till', ['--apply' => true])
            ->expectsOutputToContain('هیچ فیشی روی صندوق نمانده')
            ->assertSuccessful();

        $this->assertSame([9_600_000.0, 10_000_000.0], $this->balances());
    }

    public function test_it_moves_the_net_pay_not_the_gross(): void
    {
        // What left the shop is what the employee was handed. A command
        // that moved the gross would take the deductions off the bank
        // twice over.
        $wage = $this->wage(1_000_000);
        $wage->update(['deduction' => 250_000]);
        $wage->bankTransactions()->update(['bank_account_id' => $this->till->id]);
        BankAccount::forgetLedgerTotals();

        $this->artisan('salaries:off-the-till', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame([10_000_000.0, 9_250_000.0], $this->balances());
    }

    public function test_running_it_twice_moves_nothing_the_second_time(): void
    {
        $this->strandedOnTheTill(1_000_000);

        $this->artisan('salaries:off-the-till', ['--apply' => true])->assertSuccessful();
        $this->artisan('salaries:off-the-till', ['--apply' => true])->assertSuccessful();

        $this->assertSame([10_000_000.0, 9_000_000.0], $this->balances());
    }

    public function test_with_no_bank_to_move_to_it_refuses_rather_than_guessing(): void
    {
        $this->strandedOnTheTill(1_000_000);
        $this->bank->delete();

        $this->artisan('salaries:off-the-till', ['--apply' => true])
            ->assertFailed();

        // Left where it was. A shop with nowhere to put the money is told
        // so, not quietly rearranged. Read straight off the till — the
        // bank this helper also looks at is the one just deleted.
        $this->assertSame(
            9_000_000.0,
            round((float) $this->till->fresh()->balance, 2),
        );
    }
}
