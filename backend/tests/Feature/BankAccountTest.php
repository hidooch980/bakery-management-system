<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\FlourSale;
use App\Models\Income;
use App\Models\InventoryItem;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Money::forgetCache();

        Bakery::first()->update(['currency' => 'toman', 'flour_bag_weight_kg' => 40]);
        Money::forgetCache();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function account(array $attributes = []): BankAccount
    {
        return BankAccount::create(array_merge([
            'title' => 'حساب جاری',
            'bank_name' => 'ملت',
            'opening_balance' => 0,
        ], $attributes));
    }

    // -------------------------------------------------------- the balance

    public function test_balance_starts_at_the_opening_figure(): void
    {
        $account = $this->account(['opening_balance' => 5_000_000]);

        $this->assertEquals(5_000_000.0, $account->balance);
    }

    public function test_balance_is_derived_from_the_ledger(): void
    {
        $account = $this->account(['opening_balance' => 1_000_000]);

        $account->record('in', 500_000);
        $account->record('out', 200_000);

        $this->assertEquals(1_300_000.0, $account->fresh()->balance);
    }

    public function test_an_account_can_go_overdrawn(): void
    {
        $account = $this->account();
        $account->record('out', 100_000);

        $this->assertTrue($account->fresh()->is_overdrawn);
    }

    // -------------------------------------------------------- the default

    public function test_only_one_account_can_be_the_default(): void
    {
        $first = $this->account(['title' => 'اول', 'is_default' => true]);
        $second = $this->account(['title' => 'دوم', 'is_default' => true]);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_the_default_account_falls_back_to_any_active_one(): void
    {
        $account = $this->account(['is_default' => false]);

        $this->assertEquals($account->id, BankAccount::defaultAccount()?->id);
    }

    public function test_an_inactive_account_is_never_the_default(): void
    {
        $this->account(['is_active' => false]);

        $this->assertNull(BankAccount::defaultAccount());
    }

    // ------------------------------------------------------ auto-posting

    public function test_an_expense_paid_from_an_account_reduces_its_balance(): void
    {
        $account = $this->account(['opening_balance' => 1_000_000]);

        Expense::create([
            'category' => 'fuel',
            'title' => 'سوخت',
            'amount' => 300_000,
            'spent_on' => now(),
            'bank_account_id' => $account->id,
        ]);

        $this->assertEquals(700_000.0, $account->fresh()->balance);
    }

    public function test_miscellaneous_income_increases_the_balance(): void
    {
        $account = $this->account();

        Income::create([
            'category' => 'rent',
            'title' => 'اجاره',
            'amount' => 400_000,
            'received_on' => now(),
            'bank_account_id' => $account->id,
        ]);

        $this->assertEquals(400_000.0, $account->fresh()->balance);
    }

    public function test_a_flour_sale_paid_into_an_account_increases_it(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');

        $account = $this->account();

        FlourSale::create([
            'user_id' => $this->admin()->id,
            'unit' => 'kg',
            'quantity' => 10,
            'unit_price' => 30_000,
            'payment_type' => 'card',
            'sold_on' => now(),
            'bank_account_id' => $account->id,
        ]);

        $this->assertEquals(300_000.0, $account->fresh()->balance);
    }

    public function test_an_unpaid_salary_does_not_move_money(): void
    {
        $account = $this->account(['opening_balance' => 1_000_000]);

        SalaryPayment::create([
            'user_id' => $this->admin()->id,
            'period_start' => now()->startOfMonth(),
            'base_amount' => 500_000,
            'bonus' => 0,
            'deduction' => 0,
            'paid_on' => null,
            'bank_account_id' => $account->id,
        ]);

        // Nothing has actually been paid yet.
        $this->assertEquals(1_000_000.0, $account->fresh()->balance);
    }

    public function test_marking_a_salary_paid_moves_the_money(): void
    {
        $account = $this->account(['opening_balance' => 1_000_000]);

        $salary = SalaryPayment::create([
            'user_id' => $this->admin()->id,
            'period_start' => now()->startOfMonth(),
            'base_amount' => 500_000,
            'bonus' => 0,
            'deduction' => 0,
            'bank_account_id' => $account->id,
        ]);

        $salary->update(['paid_on' => now()]);

        $this->assertEquals(500_000.0, $account->fresh()->balance);
    }

    // ------------------------------------------- edits rebuild the posting

    public function test_editing_the_amount_rewrites_the_posting(): void
    {
        $account = $this->account(['opening_balance' => 1_000_000]);

        $expense = Expense::create([
            'category' => 'fuel',
            'title' => 'سوخت',
            'amount' => 300_000,
            'spent_on' => now(),
            'bank_account_id' => $account->id,
        ]);

        $expense->update(['amount' => 100_000]);

        // Not 1,000,000 − 300,000 − 100,000: the first posting is replaced.
        $this->assertEquals(900_000.0, $account->fresh()->balance);
        $this->assertEquals(1, $account->transactions()->count());
    }

    public function test_moving_a_payment_to_another_account_moves_the_money(): void
    {
        $first = $this->account(['title' => 'اول', 'opening_balance' => 1_000_000]);
        $second = $this->account(['title' => 'دوم', 'opening_balance' => 1_000_000]);

        $expense = Expense::create([
            'category' => 'fuel',
            'title' => 'سوخت',
            'amount' => 200_000,
            'spent_on' => now(),
            'bank_account_id' => $first->id,
        ]);

        $expense->update(['bank_account_id' => $second->id]);

        $this->assertEquals(1_000_000.0, $first->fresh()->balance);
        $this->assertEquals(800_000.0, $second->fresh()->balance);
    }

    public function test_clearing_the_account_removes_the_posting(): void
    {
        $account = $this->account(['opening_balance' => 1_000_000]);

        $expense = Expense::create([
            'category' => 'fuel',
            'title' => 'سوخت',
            'amount' => 200_000,
            'spent_on' => now(),
            'bank_account_id' => $account->id,
        ]);

        $expense->update(['bank_account_id' => null]);

        $this->assertEquals(1_000_000.0, $account->fresh()->balance);
        $this->assertEquals(0, $account->transactions()->count());
    }

    public function test_deleting_the_record_removes_its_posting(): void
    {
        $account = $this->account(['opening_balance' => 1_000_000]);

        $expense = Expense::create([
            'category' => 'fuel',
            'title' => 'سوخت',
            'amount' => 200_000,
            'spent_on' => now(),
            'bank_account_id' => $account->id,
        ]);

        $expense->delete();

        $this->assertEquals(1_000_000.0, $account->fresh()->balance);
    }

    // ----------------------------------------------------------- endpoints

    public function test_admin_creates_an_account_through_the_api(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/bank-accounts', [
                'title' => 'صندوق',
                'opening_balance' => 2_000_000,
                'is_default' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.balance', 2000000);
    }

    public function test_opening_balance_is_stored_as_toman(): void
    {
        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/bank-accounts', [
                'title' => 'صندوق',
                'opening_balance' => 10_000_000,
            ])
            ->assertCreated();

        $this->assertEquals(1_000_000.0, (float) BankAccount::first()->opening_balance);
    }

    public function test_the_index_totals_every_active_account(): void
    {
        $this->account(['title' => 'الف', 'opening_balance' => 1_000_000]);
        $this->account(['title' => 'ب', 'opening_balance' => 500_000]);
        // An inactive account is excluded from the total.
        $this->account(['title' => 'ج', 'opening_balance' => 900_000, 'is_active' => false]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/bank-accounts')
            ->assertOk()
            ->assertJsonPath('data.total_balance', 1500000);
    }

    public function test_a_manual_transaction_is_recorded(): void
    {
        $account = $this->account();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/bank-accounts/{$account->id}/transactions", [
                'direction' => 'in',
                'amount' => 750_000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.balance', 750000);
    }

    public function test_a_transfer_moves_money_between_accounts(): void
    {
        $from = $this->account(['title' => 'الف', 'opening_balance' => 1_000_000]);
        $to = $this->account(['title' => 'ب', 'opening_balance' => 0]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/bank-accounts/transfer', [
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => 400_000,
            ])
            ->assertOk();

        $this->assertEquals(600_000.0, $from->fresh()->balance);
        $this->assertEquals(400_000.0, $to->fresh()->balance);
    }

    public function test_a_transfer_to_the_same_account_is_refused(): void
    {
        $account = $this->account();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/bank-accounts/transfer', [
                'from_account_id' => $account->id,
                'to_account_id' => $account->id,
                'amount' => 1000,
            ])
            ->assertStatus(422);
    }

    public function test_an_account_with_history_cannot_be_deleted(): void
    {
        $account = $this->account();
        $account->record('in', 1000);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/bank-accounts/{$account->id}")
            ->assertStatus(409);

        $this->assertDatabaseCount('bank_accounts', 1);
    }

    public function test_updating_an_account_reaches_the_right_record(): void
    {
        $account = $this->account(['title' => 'قدیمی']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/bank-accounts/{$account->id}", [
                'title' => 'جدید',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'جدید');

        $this->assertEquals('جدید', $account->fresh()->title);
    }

    public function test_an_account_without_history_can_be_deleted(): void
    {
        $account = $this->account();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/bank-accounts/{$account->id}")
            ->assertOk();

        $this->assertDatabaseCount('bank_accounts', 0);
    }

    public function test_the_statement_lists_the_movements(): void
    {
        $account = $this->account();
        $account->record('in', 1000, 'manual');
        $account->record('out', 400, 'manual');

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/bank-accounts/{$account->id}/transactions")
            ->assertOk()
            ->assertJsonCount(2, 'data.transactions');
    }

    public function test_a_seller_cannot_see_the_accounts(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('seller');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bank-accounts')
            ->assertForbidden();
    }

    // ------------------------------------------------------------ reasons

    public function test_each_posting_carries_the_reason_it_came_from(): void
    {
        $account = $this->account();

        Income::create([
            'category' => 'rent',
            'title' => 'اجاره',
            'amount' => 100_000,
            'received_on' => now(),
            'bank_account_id' => $account->id,
        ]);

        Expense::create([
            'category' => 'fuel',
            'title' => 'سوخت',
            'amount' => 50_000,
            'spent_on' => now(),
            'bank_account_id' => $account->id,
        ]);

        $reasons = BankTransaction::pluck('reason')->sort()->values()->all();

        $this->assertEquals(['expense', 'income'], $reasons);
    }
}
