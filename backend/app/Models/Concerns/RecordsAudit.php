<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Writes down who changed this record, and what the figure was before.
 *
 * Put on the models that move money or goods. Not on everything: a log
 * that catches every row is read by nobody, and this one has to be read on
 * the day a figure is disputed.
 *
 * What it records is only the fields that actually moved. The disputed
 * number is always one number, and burying it under thirty unchanged
 * columns is how a trail stops being usable.
 */
trait RecordsAudit
{
    /** Never worth logging: they change on every save and say nothing. */
    private const NEVER = ['created_at', 'updated_at'];

    private static ?bool $auditTableExists = null;

    protected static function bootRecordsAudit(): void
    {
        static::created(fn (Model $m) => $m->writeAuditLog(
            AuditLog::CREATED,
            null,
            $m->auditableAttributes($m->getAttributes()),
        ));

        // `updated` rather than `updating`, so a save that fails leaves no
        // log claiming it happened. getOriginal() still holds the old row
        // at this point, which is the whole reason the before side exists.
        static::updated(function (Model $m) {
            $after = $m->auditableAttributes($m->getChanges());

            // A touch, or a save that changed nothing anyone cares about.
            // Writing a row here would fill the trail with «تغییر کرد» that
            // changed nothing, which is worse than silence.
            if ($after === []) {
                return;
            }

            $before = [];

            foreach (array_keys($after) as $key) {
                $before[$key] = $m->getOriginal($key);
            }

            $m->writeAuditLog(AuditLog::UPDATED, $before, $after);
        });

        static::deleted(fn (Model $m) => $m->writeAuditLog(
            AuditLog::DELETED,
            $m->auditableAttributes($m->getOriginal()),
            null,
        ));
    }

    /**
     * How this record should name itself in the log.
     *
     * Overridable, and worth overriding: the log outlives the record, so
     * «فیش حقوقی عبدالله» is the whole of what is left once a payslip is
     * deleted and its id points at nothing.
     */
    public function auditSubject(): ?string
    {
        return $this->title ?? $this->name ?? null;
    }

    /** Columns this model would rather not have written down, if any. */
    protected function auditExcept(): array
    {
        return [];
    }

    private function auditableAttributes(array $attributes): array
    {
        $skip = [...self::NEVER, ...$this->auditExcept()];

        return array_diff_key($attributes, array_flip($skip));
    }

    private function writeAuditLog(string $event, ?array $before, ?array $after): void
    {
        if (! self::auditTableExists()) {
            return;
        }

        $user = auth()->user();

        AuditLog::create([
            'event' => $event,
            'auditable_type' => $this::class,
            'auditable_id' => $this->getKey(),
            'subject' => $this->auditSubject(),
            'user_id' => $user?->id,
            // Copied, not just referenced. A user can be renamed or
            // removed, and the trail has to keep saying what it said on
            // the day — that is the difference between a record and a join.
            'user_name' => $user?->name,
            'before' => $before,
            'after' => $after,
            'ip' => request()?->ip(),
        ]);
    }

    /**
     * Whether the trail is installed here.
     *
     * Paying somebody their wages must not fail because this feature's
     * migration has not run yet. On 1405/05/29 the shop's first payslip in
     * its history — correctly written, correctly posted — ended in a red
     * error because a hook reached for a table that did not exist. The
     * lesson is cheap to apply and was expensive to learn.
     *
     * Cached per process, so this costs one query per worker rather than
     * one per save.
     */
    private static function auditTableExists(): bool
    {
        return self::$auditTableExists ??= Schema::hasTable('audit_logs');
    }
}
