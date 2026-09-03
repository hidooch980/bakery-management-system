<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The shop keeps its money in one account.
 *
 * Card takings land there and the shop pays out of it — freight, unloading,
 * fuel, flour, salt, wages, insurance. So a cost recorded anywhere has to
 * come off that account, and money in has to go onto it, or the balance is
 * a figure nobody can act on.
 */
class MoneyMovesThroughTheBankTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BankAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->account = BankAccount::create([
            'title' => 'حساب سفید',
            'is_default' => true,
            'is_active' => true,
            'opening_balance' => 1_000_000,
        ]);
    }

    private function recordExpense(array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/expenses', [
            ...[
                'category' => 'fuel',
                'title' => 'گازوئیل',
                'amount' => 200_000,
            ],
            ...$extra,
        ]);
    }

    public function test_a_cost_recorded_from_the_app_comes_off_the_account(): void
    {
        $this->recordExpense()->assertCreated();

        // Recorded from the app, where no account was ever named, so the
        // money used to leave the books without leaving the bank.
        $this->assertSame($this->account->id, Expense::first()->bank_account_id);
        $this->assertEqualsWithDelta(800_000, $this->account->fresh()->balance, 0.01);
    }

    public function test_a_cost_paid_out_of_the_till_leaves_the_account_alone(): void
    {
        $this->recordExpense(['paid_in_cash' => true])->assertCreated();

        $this->assertNull(Expense::first()->bank_account_id);
        $this->assertEqualsWithDelta(1_000_000, $this->account->fresh()->balance, 0.01);
    }

    public function test_a_named_account_is_used_over_the_default(): void
    {
        $other = BankAccount::create([
            'title' => 'حساب دوم',
            'is_active' => true,
            'opening_balance' => 500_000,
        ]);

        $this->recordExpense(['bank_account_id' => $other->id])->assertCreated();

        $this->assertEqualsWithDelta(300_000, $other->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(1_000_000, $this->account->fresh()->balance, 0.01);
    }

    public function test_money_in_goes_onto_the_account(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/incomes', [
            'category' => array_key_first(Income::CATEGORIES),
            'title' => 'فروش ضایعات',
            'amount' => 300_000,
        ])->assertCreated();

        $this->assertSame($this->account->id, Income::first()->bank_account_id);
        $this->assertEqualsWithDelta(1_300_000, $this->account->fresh()->balance, 0.01);
    }

    public function test_changing_what_a_cost_was_rewrites_what_it_took(): void
    {
        $this->recordExpense()->assertCreated();
        $expense = Expense::first();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/expenses/{$expense->id}", ['amount' => 500_000])
            ->assertOk();

        // Not 200,000 taken twice, and not the first figure left standing.
        $this->assertEqualsWithDelta(500_000, $this->account->fresh()->balance, 0.01);
    }

    public function test_deleting_a_cost_gives_the_money_back(): void
    {
        $this->recordExpense()->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/v1/expenses/'.Expense::first()->id)
            ->assertOk();

        $this->assertEqualsWithDelta(1_000_000, $this->account->fresh()->balance, 0.01);
    }

    public function test_every_kind_of_cost_the_shop_has_comes_off_the_account(): void
    {
        // Freight, unloading, fuel, rent, wages, insurance — the shop
        // pays all of it from the one account. Flour is not among them
        // any more: buying it is a purchase invoice now, and that has its
        // own account posting.
        $categories = array_slice(array_keys(Expense::CATEGORIES), 0, 6);

        foreach ($categories as $index => $category) {
            $this->recordExpense([
                'category' => $category,
                'title' => 'هزینه '.$index,
                'amount' => 100_000,
            ])->assertCreated();
        }

        $spent = count($categories) * 100_000;

        $this->assertEqualsWithDelta(
            1_000_000 - $spent,
            $this->account->fresh()->balance,
            0.01
        );
    }
}
