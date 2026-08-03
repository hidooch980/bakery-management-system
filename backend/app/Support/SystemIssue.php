<?php

namespace App\Support;

/**
 * One problem the system found in its own data, with the cause it points to
 * and what to do about it.
 *
 * An issue is never stored — it is derived from the current records every
 * time the page is opened, so fixing the underlying data makes it disappear
 * on its own rather than leaving a stale row behind.
 */
class SystemIssue
{
    public const CRITICAL = 'critical';

    public const WARNING = 'warning';

    public const INFO = 'info';

    public function __construct(
        public readonly string $key,
        public readonly string $severity,
        public readonly string $title,
        /** What the records show, in plain terms. */
        public readonly string $detail,
        /** Why this most likely happened. */
        public readonly string $cause,
        /** What to do about it. */
        public readonly string $suggestion,
        /** Where in the panel to go and deal with it, if anywhere. */
        public readonly ?string $url = null,
        public readonly ?string $urlLabel = null,
        /**
         * Set only when the fix is safe to apply automatically: one that
         * adds an explaining record rather than editing or deleting
         * anything. Receives nothing, returns what it did.
         */
        public readonly ?\Closure $autoFix = null,
        public readonly ?string $autoFixLabel = null,
    ) {}

    public function isAutoFixable(): bool
    {
        return $this->autoFix !== null;
    }

    public function severityLabel(): string
    {
        return match ($this->severity) {
            self::CRITICAL => 'بحرانی',
            self::WARNING => 'هشدار',
            default => 'اطلاع',
        };
    }

    public function color(): string
    {
        return match ($this->severity) {
            self::CRITICAL => 'danger',
            self::WARNING => 'warning',
            default => 'info',
        };
    }

    public function icon(): string
    {
        return match ($this->severity) {
            self::CRITICAL => 'heroicon-o-exclamation-triangle',
            self::WARNING => 'heroicon-o-exclamation-circle',
            default => 'heroicon-o-information-circle',
        };
    }
}
