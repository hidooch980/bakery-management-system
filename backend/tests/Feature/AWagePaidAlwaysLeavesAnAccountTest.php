<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\SalaryPayment;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A wage that is paid has to come out of something.
 *
 * The payslip model has always been able to post to an account — the
 * column, the relation and bankPostingAccountId() were all there. Nothing
 * ever filled it. Not the API, not the panel form. So every wage recorded
 * the cost and moved no money: the balance did not fall, and a shop that
 * reconciles to the Rial would have found a gap the size of its payroll
 * with nothing on any page to say where it came from.
 *
 * This is the second time today the same shape of bug has been found. The
 * first was a loan instalment whose posting had been attached by hand, and
 * it was caught only because the balance was read before and after. So it
 * is read before and after here too.
 */
class AWagePaidAlwaysLeavesAnAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $baker;

    private BankAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');

        $this->baker = User::factory()->create([
            'is_active' => true,
            'monthly_salary' => 8_000_000,
        ]);
        $this->baker->assignRole('dough_maker');

        $this->account = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 20_000_000,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    private function balance(): float
    {
        return (float) $this->account->fresh()->balance;
    }

    private function payWage(array $extra = []): array
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson('/api/v1/salaries', array_merge([
            'user_id' => $this->baker->id,
            'period_start' => '1405/05/01',
            'base_amount' => 80_000_000,
            'paid_on' => '1405/05/30',
            'bank_account_id' => $this->account->id,
        ], $extra))->assertCreated()->json('data');
    }

    public function test_paying_a_wage_takes_it_out_of_the_account(): void
    {
        $before = $this->balance();

        $this->payWage();

        // 80,000,000 Rial is 8,000,000 Toman, which is what the ledger holds.
        $this->assertEqualsWithDelta($before - 8_000_000, $this->balance(), 0.01);
    }

    public function test_the_posting_is_for_the_net_not_the_gross(): void
    {
        StaffAdvance::create([
            'user_id' => $this->baker->id,
            'recorded_by' => $this->owner->id,
            'amount' => Money::toToman(20_000_000),
            'paid_on' => now(),
        ]);

        $before = $this->balance();

        $this->payWage();

        // The advance left the account when it was handed over. Taking the
        // gross now would take it a second time.
        $this->assertEqualsWithDelta($before - 6_000_000, $this->balance(), 0.01);
    }

    public function test_a_slip_not_yet_paid_moves_nothing(): void
    {
        $before = $this->balance();

        $this->payWage(['paid_on' => null]);

        // Owed is not paid. The money is still the shop's until it is handed
        // over, and a posting here would spend it twice.
        $this->assertSame($before, $this->balance());
        $this->assertSame(0, BankTransaction::where('reason', 'salary')->count());
    }

    public function test_marking_it_paid_afterwards_moves_it_then(): void
    {
        $slip = $this->payWage(['paid_on' => null]);

        $before = $this->balance();

        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/v1/salaries/{$slip['id']}/mark-paid")->assertOk();

        $this->assertEqualsWithDelta($before - 8_000_000, $this->balance(), 0.01);
    }

    public function test_marking_it_paid_can_name_the_account(): void
    {
        $other = BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 50_000_000,
            'is_active' => true,
        ]);

        $slip = $this->payWage(['paid_on' => null, 'bank_account_id' => null]);

        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/v1/salaries/{$slip['id']}/mark-paid", [
            'bank_account_id' => $other->id,
        ])->assertOk();

        $this->assertEqualsWithDelta(50_000_000 - 8_000_000, (float) $other->fresh()->balance, 0.01);
        $this->assertSame(20_000_000.0, $this->balance());
    }

    public function test_correcting_the_wage_corrects_the_posting(): void
    {
        $slip = $this->payWage();

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/salaries/{$slip['id']}", ['base_amount' => 40_000_000])->assertOk();

        // Not 20,000,000 − 8,000,000 − 4,000,000. The posting is rebuilt,
        // not added to. A correction that stacked would be the loan
        // instalment's mistake with a different name.
        $this->assertEqualsWithDelta(20_000_000 - 4_000_000, $this->balance(), 0.01);
        $this->assertSame(1, BankTransaction::where('reason', 'salary')->count());
    }

    public function test_deleting_the_wage_gives_the_money_back(): void
    {
        $slip = $this->payWage();

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/v1/salaries/{$slip['id']}")->assertOk();

        $this->assertSame(20_000_000.0, $this->balance());
    }

    public function test_a_wage_from_the_till_moves_no_account_on_purpose(): void
    {
        $before = $this->balance();

        $this->payWage(['bank_account_id' => null]);

        // Cash out of the drawer is a real answer, and it must stay one.
        // What it must not be is what happens when the field is skipped.
        $this->assertSame($before, $this->balance());
        $this->assertNull(SalaryPayment::first()->bank_account_id);
    }

    public function test_the_sheet_is_told_which_account_to_open_on(): void
    {
        StaffAdvance::create([
            'user_id' => $this->baker->id,
            'recorded_by' => $this->owner->id,
            'amount' => Money::toToman(20_000_000),
            'paid_on' => now(),
            'bank_account_id' => $this->account->id,
        ]);

        Sanctum::actingAs($this->owner);
        $list = $this->getJson('/api/v1/salaries/employees')->assertOk()->json('data');
        $row = collect($list)->firstWhere('id', $this->baker->id);

        // Where this person's money came from last, so the field most likely
        // to be skipped opens on an answer.
        $this->assertSame($this->account->id, $row['suggested_bank_account_id']);
    }

    public function test_someone_never_paid_before_falls_back_to_the_shop_account(): void
    {
        Sanctum::actingAs($this->owner);
        $list = $this->getJson('/api/v1/salaries/employees')->assertOk()->json('data');
        $row = collect($list)->firstWhere('id', $this->baker->id);

        $this->assertSame($this->account->id, $row['suggested_bank_account_id']);
    }

    public function test_the_slip_says_where_the_money_went(): void
    {
        $this->payWage();

        Sanctum::actingAs($this->owner);
        $slip = $this->getJson('/api/v1/salaries')->assertOk()->json('data.data.0');

        $this->assertSame($this->account->id, $slip['bank_account_id']);
        $this->assertSame('حساب سفید', $slip['bank_account_title']);
    }
}
