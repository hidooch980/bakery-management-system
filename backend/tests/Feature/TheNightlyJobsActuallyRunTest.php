<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\IssueScanner;
use App\Support\SystemIssue;
use Database\Seeders\BakerySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Four commands are scheduled nightly and all four hang off one line of
 * cron calling `schedule:run`. This shop's documented crontab calls
 * `backup:database` directly and does not contain that line, which would
 * mean the other three have never run — and nothing anywhere would say so.
 *
 * The schedule is written down, it reads correctly, and it does nothing.
 * That is the same shape as the log file the error detector could not
 * open: a thing whose silence is indistinguishable from working.
 *
 * What is measured here is the consequence, not the cron line, which
 * cannot be seen from inside the application at all.
 */
class TheNightlyJobsActuallyRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BakerySeeder::class);
    }

    private function issue(): ?SystemIssue
    {
        return (new IssueScanner)->scan()
            ->first(fn (SystemIssue $i) => $i->key === 'nightly-maintenance-not-running');
    }

    private function token(?string $lastUsed, string $created): void
    {
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => User::factory()->create()->id,
            'name' => 'گوشی',
            'token' => bin2hex(random_bytes(20)),
            'abilities' => '["*"]',
            'last_used_at' => $lastUsed,
            'created_at' => $created,
            'updated_at' => $created,
        ]);
    }

    public function test_a_shop_with_no_tokens_at_all_is_not_a_finding(): void
    {
        $this->assertNull($this->issue());
    }

    public function test_a_token_in_daily_use_says_nothing(): void
    {
        $this->token(now()->subHours(2)->toDateTimeString(), now()->subYear()->toDateTimeString());

        $this->assertNull($this->issue());
    }

    public function test_a_token_idle_a_month_is_the_prunes_own_business(): void
    {
        // Twenty-nine days is inside the threshold the command itself
        // uses. Reporting it would be reporting a job for not having run
        // yet, which is not a fault.
        $this->token(now()->subDays(29)->toDateTimeString(), now()->subYear()->toDateTimeString());

        $this->assertNull($this->issue());
    }

    public function test_a_token_just_past_the_line_is_given_its_two_days(): void
    {
        // It crossed 30 this morning. Tonight's run closes it. Saying
        // «the nightly jobs are not running» on that basis would cry wolf
        // once a month for as long as the shop exists.
        $this->token(now()->subDays(31)->toDateTimeString(), now()->subYear()->toDateTimeString());

        $this->assertNull($this->issue());
    }

    public function test_a_token_left_open_past_that_means_nobody_closed_it(): void
    {
        $this->token(now()->subDays(40)->toDateTimeString(), now()->subYear()->toDateTimeString());

        $issue = $this->issue();

        $this->assertNotNull($issue);
        $this->assertSame(1.0, $issue->magnitude);

        // The owner cannot read a crontab from a phone. What he can do is
        // hand the sentence to whoever can, so it names the line.
        $this->assertStringContainsString('schedule:run', $issue->suggestion);
    }

    public function test_a_token_issued_and_never_touched_ages_from_when_it_was_made(): void
    {
        // Otherwise a key handed out and immediately dropped — the exact
        // one worth closing — never grows old enough to be noticed.
        $this->token(null, now()->subDays(40)->toDateTimeString());

        $this->assertNotNull($this->issue());
    }

    public function test_every_such_token_is_counted(): void
    {
        $this->token(now()->subDays(40)->toDateTimeString(), now()->subYear()->toDateTimeString());
        $this->token(now()->subDays(90)->toDateTimeString(), now()->subYear()->toDateTimeString());
        $this->token(now()->subHour()->toDateTimeString(), now()->subYear()->toDateTimeString());

        $this->assertSame(2.0, $this->issue()?->magnitude);
    }
}
