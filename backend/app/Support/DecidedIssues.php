<?php

namespace App\Support;

use App\Models\IssueAcknowledgement;
use Illuminate\Support\Collection;

/**
 * Which of the scanned issues the owner has already answered, and which
 * are still wanting attention.
 *
 * The issue centre has always drawn this line and the sidebar badge has
 * always counted only the open side. `shop:health` did not: it printed
 * the raw scan, so on 2026-09-03 its summary said «۷ مورد (۱ بحرانی)»
 * while the page the line points at showed four — five of the seven had
 * been answered, two of them weeks earlier.
 *
 * Both numbers were honest about something. Only one of them was about
 * the thing the sentence claimed. The rule for reading the two apart now
 * lives here, so the command and the page cannot drift again.
 */
class DecidedIssues
{
    /** @param  Collection<string, IssueAcknowledgement>  $answers */
    private function __construct(private readonly Collection $answers) {}

    public static function load(): self
    {
        return new self(
            IssueAcknowledgement::with('acknowledgedBy')->get()->keyBy('issue_key')
        );
    }

    public function answerFor(SystemIssue $issue): ?IssueAcknowledgement
    {
        return $this->answers->get($issue->key);
    }

    /**
     * Still wanting attention: never answered, or answered when it was
     * smaller than it is now.
     */
    public function isOpen(SystemIssue $issue): bool
    {
        return ! ($this->answerFor($issue)?->stillCovers($issue) ?? false);
    }

    /**
     * @param  Collection<int, SystemIssue>  $issues
     * @return Collection<int, SystemIssue>
     */
    public function open(Collection $issues): Collection
    {
        return $issues->filter(fn (SystemIssue $i) => $this->isOpen($i))->values();
    }

    /**
     * @param  Collection<int, SystemIssue>  $issues
     * @return Collection<int, SystemIssue>
     */
    public function decided(Collection $issues): Collection
    {
        return $issues->reject(fn (SystemIssue $i) => $this->isOpen($i))->values();
    }

    /**
     * How much worse an issue got since it was answered, as a share —
     * shown on one that has come back, so the owner can see why.
     */
    public function growthFor(SystemIssue $issue): ?float
    {
        return $this->answerFor($issue)?->growthSince($issue);
    }
}
