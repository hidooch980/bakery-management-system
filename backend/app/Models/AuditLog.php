<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in the shop's record of who changed a figure.
 *
 * Append-only, and enforced rather than documented: `updating` and
 * `deleting` both refuse. A log that can be rewritten answers a different
 * question from the one it was built for.
 *
 * See the migration for why this exists — four ten-times errors, none of
 * which left any trace of what the number had been before.
 */
class AuditLog extends Model
{
    use BelongsToBakery;

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    public const EVENT_LABELS = [
        self::CREATED => 'ثبت شد',
        self::UPDATED => 'تغییر کرد',
        self::DELETED => 'حذف شد',
    ];

    protected $fillable = [
        'event',
        'auditable_type',
        'auditable_id',
        'subject',
        'user_id',
        'user_name',
        'before',
        'after',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Not a guideline. An audit trail is worth exactly what it costs to
        // alter, and here that is «impossible through the app».
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** The record this happened to, when it still exists. */
    public function auditable()
    {
        return $this->morphTo();
    }

    public function getEventLabelAttribute(): string
    {
        return self::EVENT_LABELS[$this->event] ?? $this->event;
    }

    /**
     * Who did it, in words, including when the answer is «nobody signed in».
     *
     * A migration, the nightly backup and an artisan command all change
     * figures with no user attached. Saying so is more useful than a blank
     * cell, which reads as a bug rather than as a fact.
     */
    public function getActorAttribute(): string
    {
        return $this->user_name ?: ($this->user?->name ?? 'سامانه');
    }

    /**
     * The fields that moved, as «before ← after» pairs ready to read.
     *
     * Built from both sides rather than from `after` alone: a deletion has
     * no after, and a creation no before, and each still has something
     * worth showing.
     */
    public function changes(): array
    {
        $keys = array_unique([
            ...array_keys($this->before ?? []),
            ...array_keys($this->after ?? []),
        ]);

        return array_map(fn (string $key) => [
            'field' => $key,
            'from' => $this->before[$key] ?? null,
            'to' => $this->after[$key] ?? null,
        ], $keys);
    }
}
