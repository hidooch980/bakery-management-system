<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Income;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money in, counted twice, is the one that shows up in the drawer.
 *
 * Miscellaneous income now lands in the till by default. A receipt
 * entered twice therefore puts the till ahead of the notes actually in
 * it — and nothing says so until somebody counts and comes up short, by
 * which time the day it happened is weeks back.
 *
 * Card income is guarded the same way: it inflates the bank instead of
 * the drawer, and the shop finds that out from a statement rather than
 * from a count. Neither is better.
 */
class AnIncomeTypedTwiceIsCaughtTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BankAccount $till;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 0,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->till = BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 0,
            'is_active' => true,
            'is_cash_box' => true,
        ]);
    }

    private function send(array $overrides = [])
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/incomes', array_merge([
                'category' => 'scrap',
                'title' => 'فروش کیسه خالی',
                'amount' => 2_000_000,
            ], $overrides));
    }

    public function test_the_same_money_twice_in_a_day_is_refused_and_the_first_is_named(): void
    {
        $first = $this->send()->assertCreated()->json('data.id');

        $this->send()
            ->assertStatus(409)
            ->assertJsonPath('data.duplicate_of', $first);

        $this->assertSame(1, Income::count());
    }

    public function test_the_till_does_not_run_ahead_of_the_drawer(): void
    {
        $this->send()->assertCreated();

        $this->assertEqualsWithDelta(2_000_000, (float) $this->till->fresh()->balance, 0.01);

        $this->send()->assertStatus(409);

        // The whole point: the refusal leaves the till reading what is
        // actually in it.
        $this->assertEqualsWithDelta(2_000_000, (float) $this->till->fresh()->balance, 0.01);
    }

    public function test_a_refused_income_leaves_nothing_in_the_audit_trail(): void
    {
        $this->send()->assertCreated();
        $before = AuditLog::count();

        $this->send()->assertStatus(409);

        $this->assertSame($before, AuditLog::count());
    }

    public function test_money_really_taken_twice_is_recorded_when_the_caller_says_so(): void
    {
        $this->send()->assertCreated();
        $this->send(['force' => true])->assertCreated();

        $this->assertSame(2, Income::count());
        $this->assertEqualsWithDelta(4_000_000, (float) $this->till->fresh()->balance, 0.01);
    }

    public function test_card_income_is_guarded_too(): void
    {
        // It inflates the bank rather than the drawer, and the shop finds
        // that out from a statement instead of from a count.
        $this->send(['by_card' => true])->assertCreated();
        $this->send(['by_card' => true])->assertStatus(409);

        $this->assertSame(1, Income::count());
    }

    public function test_a_different_kind_or_amount_or_day_is_different_money(): void
    {
        $this->send()->assertCreated();

        $this->send(['category' => 'service'])->assertCreated();
        $this->send(['amount' => 500_000])->assertCreated();
        $this->send(['received_on' => now()->subDay()->toDateString()])->assertCreated();

        $this->assertSame(4, Income::count());
    }
}
