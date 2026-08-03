<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\SalaryPayment;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pay handed over before payday, and taken back off the next payslip.
 */
class StaffAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Money::forgetCache();

        $this->staff = User::factory()->create(['is_active' => true]);
        $this->staff->assignRole('seller');
    }

    private function advance(float $amount, ?BankAccount $account = null): StaffAdvance
    {
        return StaffAdvance::create([
            'user_id' => $this->staff->id,
            'amount' => $amount,
            'paid_on' => now(),
            'bank_account_id' => $account?->id,
        ]);
    }

    private function payslip(float $base, float $bonus = 0, float $deduction = 0): SalaryPayment
    {
        return SalaryPayment::create([
            'user_id' => $this->staff->id,
            'period_start' => now()->startOfMonth(),
            'base_amount' => $base,
            'bonus' => $bonus,
            'deduction' => $deduction,
        ]);
    }

    public function test_an_advance_is_recovered_from_the_next_payslip(): void
    {
        $this->advance(500_000);

        $payslip = $this->payslip(3_000_000);

        $this->assertSame('500000.00', $payslip->advance_deduction);
        $this->assertSame('2500000.00', $payslip->net_amount);
        $this->assertTrue($this->advance_first()->is_settled);
    }

    public function test_the_hand_entered_deduction_is_kept_separate(): void
    {
        $this->advance(200_000);

        $payslip = $this->payslip(3_000_000, bonus: 100_000, deduction: 50_000);

        // 3,000,000 + 100,000 − 50,000 − 200,000
        $this->assertSame('200000.00', $payslip->advance_deduction);
        $this->assertSame('50000.00', $payslip->deduction);
        $this->assertSame('2850000.00', $payslip->net_amount);
    }

    public function test_an_advance_larger_than_the_pay_carries_to_the_month_after(): void
    {
        $this->advance(5_000_000);

        $first = $this->payslip(2_000_000);

        // The payslip is taken to zero, never below it.
        $this->assertSame('2000000.00', $first->advance_deduction);
        $this->assertSame('0.00', $first->net_amount);
        $this->assertSame(3_000_000.0, StaffAdvance::outstandingFor($this->staff->id));

        $second = SalaryPayment::create([
            'user_id' => $this->staff->id,
            'period_start' => now()->addMonth()->startOfMonth(),
            'base_amount' => 2_000_000,
        ]);

        $this->assertSame('2000000.00', $second->advance_deduction);
        $this->assertSame(1_000_000.0, StaffAdvance::outstandingFor($this->staff->id));
    }

    public function test_resaving_a_payslip_does_not_recover_the_advance_twice(): void
    {
        $this->advance(400_000);

        $payslip = $this->payslip(3_000_000);
        $this->assertSame('400000.00', $payslip->advance_deduction);

        $payslip->update(['bonus' => 100_000]);

        $this->assertSame('400000.00', $payslip->fresh()->advance_deduction);
        $this->assertSame('2700000.00', $payslip->fresh()->net_amount);
        $this->assertSame(0.0, StaffAdvance::outstandingFor($this->staff->id));
    }

    public function test_deleting_a_payslip_hands_the_advance_back(): void
    {
        $this->advance(400_000);

        $payslip = $this->payslip(3_000_000);
        $this->assertSame(0.0, StaffAdvance::outstandingFor($this->staff->id));

        $payslip->delete();

        // The money is owed again — it was never actually recovered.
        $this->assertSame(400_000.0, StaffAdvance::outstandingFor($this->staff->id));
    }

    public function test_one_advance_split_across_two_payslips_survives_deleting_the_first(): void
    {
        $this->advance(5_000_000);

        $first = $this->payslip(2_000_000);
        $second = SalaryPayment::create([
            'user_id' => $this->staff->id,
            'period_start' => now()->addMonth()->startOfMonth(),
            'base_amount' => 2_000_000,
        ]);

        $this->assertSame(1_000_000.0, StaffAdvance::outstandingFor($this->staff->id));

        $first->delete();

        // Only the first payslip's 2,000,000 comes back; the second one's
        // recovery is untouched.
        $this->assertSame(3_000_000.0, StaffAdvance::outstandingFor($this->staff->id));
        $this->assertSame('2000000.00', $second->fresh()->advance_deduction);
    }

    public function test_the_advance_leaves_the_account_it_was_paid_from(): void
    {
        $account = BankAccount::create([
            'title' => 'صندوق',
            'opening_balance' => 10_000_000,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->advance(750_000, $account);

        $this->assertSame(9_250_000.0, $account->fresh()->balance);
    }

    public function test_an_advance_is_not_an_expense(): void
    {
        $this->advance(500_000);

        // Pay brought forward is not a cost; it becomes one when the payslip
        // it is recovered from counts as one.
        $this->assertSame(0, Expense::count());
    }

    private function advance_first(): StaffAdvance
    {
        return StaffAdvance::where('user_id', $this->staff->id)->firstOrFail();
    }
}
