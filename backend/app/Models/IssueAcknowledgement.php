<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Support\SystemIssue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The owner's answer to something the issue centre reported.
 *
 * Not a dismissal — the issue stays on the page, in a decided list, with
 * the reason beside it. What changes is that it stops counting as open.
 */
class IssueAcknowledgement extends Model
{
    use BelongsToBakery;

    /**
     * How much a problem may grow before an answer given about the smaller
     * one stops applying to it.
     *
     * A fifth. Small enough that a debt doubling comes back, wide enough
     * that a balance drifting by a day's takings does not reopen a decision
     * every morning.
     */
    private const GROWTH_THAT_REOPENS = 1.20;

    protected $fillable = [
        'issue_key',
        'title',
        'severity',
        'note',
        'magnitude',
        'acknowledged_by',
    ];

    protected $casts = [
        'magnitude' => 'float',
    ];

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * Does this answer still cover the issue as it stands now?
     *
     * An issue with no magnitude — a missing setting, an empty category —
     * is the same issue it was, so the answer holds. One that has grown by
     * more than a fifth is a different problem wearing the same key.
     */
    public function stillCovers(SystemIssue $issue): bool
    {
        if ($issue->magnitude === null || $this->magnitude === null) {
            return true;
        }

        if ($this->magnitude <= 0) {
            // Answered when there was nothing to measure; any size now is
            // new. Guards the division as well.
            return $issue->magnitude <= 0;
        }

        return $issue->magnitude <= $this->magnitude * self::GROWTH_THAT_REOPENS;
    }

    /** How much bigger the problem got, as a share — for telling the owner why it is back. */
    public function growthSince(SystemIssue $issue): ?float
    {
        if ($issue->magnitude === null || ! $this->magnitude) {
            return null;
        }

        return $issue->magnitude / $this->magnitude - 1;
    }
}
