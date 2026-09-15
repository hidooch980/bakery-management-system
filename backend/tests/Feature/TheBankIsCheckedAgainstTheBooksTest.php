<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashCount;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The same question the drawer gets, asked of a bank account.
 *
 * The shop's «حساب سفید» drifted 70,292,603 Toman from the bank by
 * 1405/05. What closed it was a hand-typed withdrawal labelled «برداشت
 * شخصی: اختلاف» — the gap gone from the screen with the cause never
 * found. A month later it had reopened at 13,022,850.
 *
 * Counting the drawer was already possible and none of it was ever
 * cash-specific: the history, the «adjust» decision that is always the
 * owner's, the audit line. Only the controller was, and it read
 * `cashBox()` in two places.
 *
 * What the account is decides the wording, not the machinery — a drawer
 * is counted, a bank account is read off a statement, and a history that
 * files both under «شمارش صندوق» cannot answer which account a line
 * belonged to.
 */
class TheBankIsCheckedAgainstTheBooksTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BankAccount $till;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->till = BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 1_000_000,
            'is_active' => true,
            'is_cash_box' => true,
        ]);

        $this->bank = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 10_000_000,
            'is_active' => true,
            'is_cash_box' => false,
        ]);
    }

    private function check(array $body = [])
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/cash-counts', $body);
    }

    public function test_an_account_can_be_named_and_is_the_one_recorded(): void
    {
        $this->check([
            'counted_amount' => 10_000_000,
            'account_id' => $this->bank->id,
        ])->assertCreated();

        $this->assertSame(
            $this->bank->id,
            CashCount::latest('id')->first()->bank_account_id,
        );
    }

    public function test_naming_nothing_still_counts_the_till(): void
    {
        // Every app already on a phone sends no account_id. If that
        // stopped meaning «the till», the shop would lose its cash count
        // the moment this deployed.
        $this->check(['counted_amount' => 1_000_000])->assertCreated();

        $this->assertSame(
            $this->till->id,
            CashCount::latest('id')->first()->bank_account_id,
        );
    }

    public function test_the_gap_is_measured_against_that_accounts_own_books(): void
    {
        $this->check([
            'counted_amount' => 12_500_000,
            'account_id' => $this->bank->id,
            'adjust' => true,
        ])->assertCreated();

        $count = CashCount::latest('id')->first();

        $this->assertSame(10_000_000.0, (float) $count->expected_amount);
        $this->assertSame(2_500_000.0, $count->difference);
    }

    public function test_the_correction_lands_on_that_account_and_says_which(): void
    {
        $this->check([
            'counted_amount' => 12_500_000,
            'account_id' => $this->bank->id,
            'adjust' => true,
        ])->assertCreated();

        $posting = BankTransaction::where('bank_account_id', $this->bank->id)
            ->where('reason', 'manual')
            ->latest('id')
            ->first();

        $this->assertNotNull($posting);
        $this->assertSame('in', $posting->direction);
        $this->assertSame(2_500_000.0, (float) $posting->amount);

        // Named for the account, not for a drawer it never touched.
        $this->assertStringContainsString('حساب سفید', $posting->note);
        $this->assertStringNotContainsString('صندوق', $posting->note);

        // And nothing was written against the till.
        $this->assertSame(
            0,
            BankTransaction::where('bank_account_id', $this->till->id)
                ->where('reason', 'manual')
                ->count(),
        );
    }

    public function test_without_adjust_the_books_are_left_disagreeing(): void
    {
        $this->check([
            'counted_amount' => 12_500_000,
            'account_id' => $this->bank->id,
        ])->assertCreated();

        // The record exists to disagree. Correcting is a separate
        // decision and stays the owner's.
        $this->assertSame(
            0,
            BankTransaction::where('bank_account_id', $this->bank->id)
                ->where('reason', 'manual')
                ->count(),
        );

        $this->assertSame(2_500_000.0, CashCount::latest('id')->first()->difference);
    }

    public function test_a_closed_account_is_refused(): void
    {
        $this->bank->update(['is_active' => false]);

        $this->check([
            'counted_amount' => 10_000_000,
            'account_id' => $this->bank->id,
        ])->assertStatus(422);

        $this->assertSame(0, CashCount::count());
    }

    public function test_the_screen_is_offered_every_account_it_could_ask_about(): void
    {
        $titles = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/cash-counts')
            ->assertOk()
            ->json('data.accounts.*.title');

        $this->assertContains('حساب سفید', $titles);
        $this->assertContains('صندوق نقد', $titles);
    }

    public function test_a_named_account_is_what_the_history_comes_back_for(): void
    {
        $this->check(['counted_amount' => 10_000_000, 'account_id' => $this->bank->id]);
        $this->check(['counted_amount' => 1_000_000]);

        $body = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/cash-counts?account_id='.$this->bank->id)
            ->assertOk()
            ->json('data');

        $this->assertSame('حساب سفید', $body['account']['title']);
        $this->assertCount(1, $body['counts']);
    }
}
