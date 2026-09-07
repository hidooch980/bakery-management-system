<?php

namespace Tests\Feature;

use App\Support\IssueScanner;
use App\Support\SystemIssue;
use Database\Seeders\BakerySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every kind of failure this application expects has a message. What has
 * none is the kind nobody expected: those go to the log file, which on a
 * shop floor is nowhere.
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

    /**
     * The name the shop's own server writes, not the one the `single`
     * driver would. Every test in this file used to write `laravel.log`,
     * so every test passed while the detector — which looked for exactly
     * that name — found nothing on the real machine and reported «no
     * errors» every day. A fixture that agrees with the code instead of
     * with the server proves nothing about the server.
     */
    private function dailyLog(): string
    {
        return $this->logDir().'/laravel-'.now()->format('Y-m-d').'.log';
    }

    /**
     * Not `storage/logs`. This detector is the only one that reads a real
     * file, so in tests it is pointed at a directory of its own — otherwise
     * a machine that happened to log an error today would fail every test
     * elsewhere that asserts a clean shop has nothing to report.
     */
    private function logDir(): string
    {
        return rtrim((string) config('logging.issue_scan_dir'), '/');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BakerySeeder::class);

        $this->log = $this->dailyLog();

        if (! is_dir(dirname($this->log))) {
            mkdir(dirname($this->log), 0o775, true);
        }

        file_put_contents($this->log, '');
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        @unlink($this->logDir().'/laravel.log');

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
     * The other driver's name still works. A server configured the other
     * way round should not go quiet either, and which of the two a shop
     * runs is a line in its `.env` that nobody looks at again.
     */
    public function test_the_single_drivers_name_is_read_too(): void
    {
        @unlink($this->log);

        file_put_contents(
            $this->logDir().'/laravel.log',
            $this->line('08:00').$this->line('09:00').$this->line('10:00'),
        );

        $this->assertNotNull($this->issue());
    }

    /**
     * Today's file is the one asked for. Yesterday's sits in the same
     * directory under its own name and its errors are not today's news.
     */
    public function test_yesterdays_file_is_not_read(): void
    {
        @unlink($this->log);

        $yesterday = $this->logDir()
            .'/laravel-'.now()->subDay()->format('Y-m-d').'.log';

        file_put_contents(
            $yesterday,
            $this->line('08:00').$this->line('09:00').$this->line('10:00'),
        );

        try {
            $this->assertNull($this->issue());
        } finally {
            @unlink($yesterday);
        }
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
