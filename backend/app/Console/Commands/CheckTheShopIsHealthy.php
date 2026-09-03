<?php

namespace App\Console\Commands;

use App\Models\Bakery;
use App\Support\DecidedIssues;
use App\Support\IssueScanner;
use App\Support\ShopHealth;
use App\Support\SystemIssue;
use Illuminate\Console\Command;

/**
 * One command that asks the whole shop whether it adds up.
 *
 * These checks were being run by hand, a query at a time, every time
 * somebody asked «چک کن» — which is slow, expensive, and worst of all not
 * repeatable: a check nobody wrote down gets remembered differently the
 * next time, and the thing it caught last month goes unlooked-at.
 *
 *     php artisan shop:health
 *
 * Read-only. Every figure is derived from records already in the database
 * and nothing here writes, so it is safe against production at any hour.
 *
 * The checks themselves now live in `ShopHealth`, because the owner never
 * saw them here — running a command over SSH is not a thing he does, so he
 * asked me to run it instead, and on 1405/06/07 four days passed with a
 * 400 kg hole in the ledger and every screen he had showing green. The
 * panel reads the same class, so the two cannot answer differently.
 *
 * It is deliberately not the issue centre. That reports what the *shop*
 * must act on — a debt to chase, a quota running out. This reports whether
 * the *system* is telling the truth: whether ledgers reconcile, whether
 * every record that should have moved stock did, whether the backups are
 * running. A shop can be perfectly healthy here and still owe money.
 *
 * Exits non-zero when a check fails, so it can be run from cron or a
 * deploy script and actually be noticed.
 */
class CheckTheShopIsHealthy extends Command
{
    protected $signature = 'shop:health {--quiet-when-clean : Print nothing unless something is wrong}';

    protected $description = 'Checks every cycle in the shop against itself';

    public function handle(): int
    {
        $health = ShopHealth::inspect();

        foreach ($health->cycles() as $heading => $rows) {
            if ($this->option('quiet-when-clean') && $health->isSpotless()) {
                continue;
            }

            $this->newLine();
            $this->line("<options=bold>{$heading}</>");

            foreach ($rows as $row) {
                $this->line('  '.$this->paint($row));
            }
        }

        return $this->summarise($health);
    }

    /** @param  array{severity: string, label: string}  $row */
    private function paint(array $row): string
    {
        return match ($row['severity']) {
            ShopHealth::OK => "<fg=green>✓</> {$row['label']}",
            ShopHealth::WARN => "<fg=yellow>!</> {$row['label']}",
            ShopHealth::FAIL => "<fg=red>✗</> {$row['label']}",
            default => $row['label'],
        };
    }

    /**
     * The issue centre's own count, drawn the way that page draws it.
     *
     * This line used to print the raw scan. On 2026-09-03 it read «۷ مورد
     * (۱ بحرانی)» while the page it names showed four: five of the seven
     * had been answered, two of them weeks before. A summary that points
     * at a screen and disagrees with it teaches the reader to trust
     * neither — and it was read aloud twice that morning as though every
     * one of the seven were waiting.
     *
     * Decided ones are still said, quietly, on their own line. They are
     * not nothing: an answer covers a problem at the size it was, so a
     * long decided list is a list that will come back one at a time.
     */
    private function summarise(ShopHealth $health): int
    {
        $issues = (new IssueScanner(Bakery::first()))->scan();
        $decided = DecidedIssues::load();

        $open = $decided->open($issues);
        $answered = $decided->decided($issues);
        $critical = $open->where('severity', SystemIssue::CRITICAL)->count();

        $this->newLine();
        $this->line('<options=bold>خلاصه</>');
        $this->line(sprintf(
            '  صفحهٔ مشکلات: %d مورد باز (%d بحرانی) — اینها کار مغازه است، نه خرابی سیستم',
            $open->count(),
            $critical
        ));

        if ($answered->isNotEmpty()) {
            $this->line(sprintf(
                '  و %d مورد که پاسخ داده‌اید — اگر بزرگ‌تر شوند برمی‌گردند',
                $answered->count()
            ));
        }

        if ($health->isSpotless()) {
            $this->newLine();
            $this->info('  همه‌ی چرخه‌ها با خودشان می‌خوانند.');

            return self::SUCCESS;
        }

        foreach ($health->warnings() as $warning) {
            $this->line("  <fg=yellow>!</> {$warning}");
        }

        foreach ($health->failures() as $failure) {
            $this->line("  <fg=red>✗</> {$failure}");
        }

        $this->newLine();

        // A warning is something to look at; a failure is something wrong
        // with the system itself, and only that fails the command.
        return $health->isSound() ? self::SUCCESS : self::FAILURE;
    }
}
