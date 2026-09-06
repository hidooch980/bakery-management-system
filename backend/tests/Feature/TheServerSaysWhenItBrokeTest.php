<?php

namespace Tests\Feature;

use App\Support\IssueScanner;
use App\Support\SystemIssue;
use Database\Seeders\BakerySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every kind of failure this application expects has a message. What has
 * none is the kind nobody expected: those go to `storage/logs/laravel.log`,
 * which on a shop floor is nowhere.
 *
 * The phone was blind the same way until today, and it cost five releases
 * of guessing at «کار نکرد» while the message that named the type and the
 * file was being written and thrown away as it happened. This is the same
 * thing on the server, counted on the page the owner already reads.
 */
class TheServerSaysWhenItBrokeTest extends TestCase
{
    use RefreshDatabase;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BakerySeeder::class);

        $this->log = storage_path('logs/laravel.log');

        if (! is_dir(dirname($this->log))) {
            mkdir(dirname($this->log), 0o775, true);
        }

        file_put_contents($this->log, '');
    }

    protected function tearDown(): void
    {
        @unlink($this->log);

        parent::tearDown();
    }

    private function write(string $contents): void
    {
        file_put_contents($this->log, $contents);
    }

    private function issue(): ?SystemIssue
    {
        return (new IssueScanner)->scan()
            ->first(fn (SystemIssue $i) => $i->key === 'server-errors-today');
    }

    private function line(string $time, string $level = 'ERROR'): string
    {
        return '['.now()->format('Y-m-d').' '.$time.'] production.'
            .$level.': something went wrong'."\n";
    }

    public function test_a_quiet_day_says_nothing(): void
    {
        $this->write($this->line('08:00', 'INFO'));

        $this->assertNull($this->issue());
    }

    public function test_one_error_is_a_fact_of_life_and_not_a_finding(): void
    {
        $this->write($this->line('08:00'));

        $this->assertNull($this->issue());
    }

    public function test_a_handful_in_one_day_is_something_breaking(): void
    {
        $this->write($this->line('08:00').$this->line('09:00').$this->line('10:30'));

        $issue = $this->issue();

        $this->assertNotNull($issue);
        $this->assertStringContainsString('3', $issue->detail);

        // The hour, because «چه ساعتی» is answerable from the shop floor
        // and narrows it further than a class name would.
        $this->assertStringContainsString('10:30', $issue->detail);
    }

    public function test_yesterdays_errors_are_yesterdays(): void
    {
        $yesterday = now()->subDay()->format('Y-m-d');

        $this->write(str_repeat(
            '['.$yesterday.' 08:00] production.ERROR: something went wrong'."\n",
            10,
        ));

        $this->assertNull($this->issue());
    }

    public function test_a_critical_counts_the_same_as_an_error(): void
    {
        $this->write(
            $this->line('08:00', 'CRITICAL')
            .$this->line('09:00', 'CRITICAL')
            .$this->line('10:00')
        );

        $this->assertNotNull($this->issue());
    }

    public function test_no_log_at_all_is_not_a_problem_to_report(): void
    {
        @unlink($this->log);

        $this->assertNull($this->issue());
    }

    /**
     * The scanner runs on every «امروز». A log left to grow for months
     * must not be read into memory to answer «did anything break today».
     */
    public function test_a_very_large_log_is_read_from_the_end(): void
    {
        $old = str_repeat(
            '['.now()->subMonth()->format('Y-m-d').' 08:00] production.ERROR: old'."\n",
            40_000,
        );

        $this->write($old.$this->line('08:00').$this->line('09:00').$this->line('10:00'));

        $issue = $this->issue();

        $this->assertNotNull($issue);
        $this->assertSame(3.0, $issue->magnitude);
    }
}
