<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\User;
use App\Support\IssueScanner;
use App\Support\Money;
use App\Support\SystemIssue;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The machine loan falls due on the 10th of every month, in one transfer,
 * and until now nothing said so anywhere the owner looks.
 *
 * The loan page always knew the date — `next_due_on` is worked forward from
 * the first instalment by however many have been paid — but knowing it on a
 * page nobody opens between the 1st and the 10th is the same as not knowing
 * it. A missed bank instalment costs a penalty, and is the sort of thing
 * that gets noticed a month late.
 */
class LoanInstalmentIsChasedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
            'bread_price' => 5000,
            'currency' => 'toman',
        ]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
    }

    /** @param  int  $dueInDays  negative for an instalment already past */
    private function loan(int $dueInDays): Loan
    {
        return Loan::create([
            'title' => 'وام خرید دستگاه',
            'lender' => 'بانک صادرات',
            'principal' => 153_834_400,
            'instalment_amount' => 4_000_000,
            'instalment_count' => 40,
            'first_due_on' => now()->addDays($dueInDays),
        ]);
    }

    private function issue(Loan $loan): ?SystemIssue
    {
        return app(IssueScanner::class)->scan()->firstWhere('key', "loan-due-{$loan->id}");
    }

    public function test_an_instalment_a_month_off_is_not_worth_saying(): void
    {
        $this->assertNull($this->issue($this->loan(30)));
    }

    public function test_an_instalment_a_few_days_off_is_a_warning(): void
    {
        $issue = $this->issue($this->loan(3));

        $this->assertNotNull($issue);
        $this->assertSame(SystemIssue::WARNING, $issue->severity);
        // Warned before the date, not on it: the money has to be in the
        // account before the transfer, not after.
        $this->assertStringContainsString('نزدیک', $issue->title);
    }

    public function test_a_missed_instalment_is_critical(): void
    {
        $issue = $this->issue($this->loan(-5));

        $this->assertNotNull($issue);
        $this->assertSame(SystemIssue::CRITICAL, $issue->severity);
        $this->assertStringContainsString('عقب افتاده', $issue->title);
    }

    public function test_it_says_what_is_owed_and_what_is_left(): void
    {
        $issue = $this->issue($this->loan(-5));

        $this->assertStringContainsString(Money::format(4_000_000), $issue->detail);
        $this->assertStringContainsString(Money::format(153_834_400), $issue->detail);
    }

    public function test_paying_it_moves_the_date_on_and_clears_the_warning(): void
    {
        $loan = $this->loan(-5);
        $this->assertNotNull($this->issue($loan));

        LoanPayment::create([
            'loan_id' => $loan->id,
            'amount' => 4_000_000,
            'paid_on' => now(),
        ]);

        // The schedule is worked forward from instalments paid, so the
        // next one is now a month out — nothing to chase.
        $this->assertNull($this->issue($loan->fresh()));
    }

    public function test_a_settled_loan_says_nothing(): void
    {
        $loan = $this->loan(-5);
        $loan->update(['settled_on' => now()]);

        $this->assertNull($this->issue($loan));
    }

    public function test_a_loan_with_no_schedule_says_nothing(): void
    {
        $loan = $this->loan(-5);
        $loan->update(['first_due_on' => null]);

        // Nothing to chase: a loan recorded without a due date is a
        // balance, not a timetable.
        $this->assertNull($this->issue($loan));
    }

    public function test_how_late_it_is_is_what_grows(): void
    {
        // The magnitude is what stops an answer given about a loan one day
        // late from covering the same loan a month late.
        $this->assertEqualsWithDelta(5.0, $this->issue($this->loan(-5))->magnitude, 1.0);
        $this->assertSame(0.0, $this->issue($this->loan(3))->magnitude);
    }
}
