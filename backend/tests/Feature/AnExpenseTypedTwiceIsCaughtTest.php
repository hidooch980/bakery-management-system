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
 * The diesel is paid for, and paid for again that night from the receipt.
 *
 * The same shape as the duplicated invoice the owner reported, one form
 * over: an expense typed at the pump and again from the paper, with
 * nothing able to tell the second from a second tankful. Unlike the
 * invoice this moves no stock — only money — so it is guarded at the
 * moment of typing and not listed afterwards: identical costs on one day
 * are ordinary enough that a list of them would be noise.
 */
class AnExpenseTypedTwiceIsCaughtTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BankAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->account = BankAccount::create([
            'title' => 'حساب اصلی',
            'opening_balance' => 100_000_000,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    private function send(array $overrides = [])
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/expenses', array_merge([
                'category' => 'fuel',
                'title' => 'گازوئیل',
                'amount' => 3_000_000,
            ], $overrides));
    }

    public function test_the_same_cost_twice_in_a_day_is_refused_and_the_first_is_named(): void
    {
        $first = $this->send()->assertCreated()->json('data.id');

        $this->send()
            ->assertStatus(409)
            ->assertJsonPath('data.duplicate_of', $first);

        $this->assertSame(1, Expense::count());
    }

    public function test_the_refused_expense_leaves_no_money_behind(): void
    {
        // The row is written before it can be compared, so the refusal has
        // to take its bank posting with it. A cost that was refused and
        // still came off the account would be the worst of both.
        $this->send()->assertCreated();
        $after = (float) $this->account->fresh()->balance;

        $this->send()->assertStatus(409);

        $this->assertEqualsWithDelta($after, (float) $this->account->fresh()->balance, 0.01);
    }

    public function test_a_second_payment_is_recorded_when_the_caller_says_so(): void
    {
        $this->send()->assertCreated();
        $this->send(['force' => true])->assertCreated();

        $this->assertSame(2, Expense::count());
    }

    public function test_a_different_amount_is_a_different_cost(): void
    {
        $this->send()->assertCreated();
        $this->send(['amount' => 1_500_000])->assertCreated();

        $this->assertSame(2, Expense::count());
    }

    public function test_a_different_category_is_a_different_cost(): void
    {
        $this->send()->assertCreated();
        $this->send(['category' => 'maintenance'])->assertCreated();

        $this->assertSame(2, Expense::count());
    }

    public function test_a_cost_on_another_day_is_not_a_twin(): void
    {
        $this->send(['spent_on' => now()->subDay()->toDateString()])->assertCreated();
        $this->send()->assertCreated();

        $this->assertSame(2, Expense::count());
    }

    public function test_the_title_is_not_what_makes_them_the_same(): void
    {
        // «گازوئیل» and «گازوییل» are one payment typed twice.
        $this->send()->assertCreated();
        $this->send(['title' => 'گازوییل'])->assertStatus(409);

        $this->assertSame(1, Expense::count());
    }
}
