<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Costs entered from the phone by a Rial shop were stored ten times over.
 * Correcting the number alone is not enough: each cost has a bank posting
 * behind it, and an account still counting ten times the money is the same
 * error wearing a different hat.
 */
class CorrectingTenTimesCostsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_08_11_090000_correct_the_costs_that_were_stored_ten_times_over.php'
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
    }

    private function expense(float $amount, ?BankAccount $account = null): Expense
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return Expense::create([
            'user_id' => $user->id,
            'category' => array_key_first(Expense::CATEGORIES),
            'title' => 'آرد',
            'amount' => $amount,
            'spent_on' => now(),
            'bank_account_id' => $account?->id,
        ]);
    }

    public function test_a_cost_comes_down_by_a_factor_of_ten(): void
    {
        $expense = $this->expense(11_664_000);

        $this->migration()->up();

        $this->assertEquals(1_166_400.0, (float) $expense->fresh()->amount);
    }

    public function test_the_bank_posting_comes_down_with_it(): void
    {
        $account = BankAccount::create([
            'title' => 'حساب اصلی',
            'opening_balance' => 0,
            'is_active' => true,
        ]);

        $expense = $this->expense(2_000_000, $account);

        // The posting starts out as wrong as the cost it came from.
        $this->assertEquals(-2_000_000.0, round($account->fresh()->balance, 2));

        $this->migration()->up();

        // A raw UPDATE would have left this at -2,000,000.
        $this->assertEquals(-200_000.0, round($account->fresh()->balance, 2));
        $this->assertEquals(200_000.0, (float) $expense->fresh()->amount);
    }

    public function test_it_can_be_put_back(): void
    {
        $expense = $this->expense(2_000_000);

        $this->migration()->up();
        $this->migration()->down();

        $this->assertEquals(2_000_000.0, (float) $expense->fresh()->amount);
    }

    public function test_a_cost_with_no_account_is_still_corrected(): void
    {
        // Paid out of the till: nothing to post, but the figure is no less
        // wrong for it.
        $expense = $this->expense(750_000);

        $this->migration()->up();

        $this->assertEquals(75_000.0, (float) $expense->fresh()->amount);
        $this->assertCount(0, $expense->fresh()->bankTransactions);
    }
}
