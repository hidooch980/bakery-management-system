<?php

namespace App\Models;

use App\Support\AppCalendar;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * One term a bakery has paid for.
 *
 * Deliberately *not* using BelongsToBakery. Every other model in this
 * system is scoped to the current shop so one shop cannot read another's
 * figures; this one is about which shops may run at all, and a shop
 * reading its own entitlement through a scope it also controls is the
 * shape of a lock fitted to the inside of the door.
 *
 * A renewal is a new row. The current term is the latest one that has
 * started and has not been cancelled — so «until when» and «what have
 * they paid over two years» are the same record read two ways.
 */
class Subscription extends Model
{
    protected $fillable = [
        'bakery_id',
        'plan',
        'starts_on',
        'ends_on',
        'amount',
        'created_by',
        'note',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function bakery()
    {
        return $this->belongsTo(Bakery::class);
    }

    /**
     * The term a shop is on today, or null if it has never had one.
     *
     * The latest term that has started, cancelled ones aside. An expired
     * term is still the current one: a shop whose subscription ran out
     * last week is on a lapsed subscription, not on none, and the two
     * read very differently to whoever has to ring them up.
     */
    public static function currentFor(int $bakeryId): ?self
    {
        return static::query()
            ->where('bakery_id', $bakeryId)
            ->whereNull('cancelled_at')
            ->whereDate('starts_on', '<=', now())
            ->orderByDesc('ends_on')
            ->orderByDesc('id')
            ->first();
    }

    public function getIsCurrentAttribute(): bool
    {
        return $this->cancelled_at === null
            && $this->ends_on !== null
            && $this->ends_on->endOfDay()->greaterThanOrEqualTo(now());
    }

    /**
     * Days left, negative once it has lapsed.
     *
     * Signed on purpose. «۳ روز مانده» and «۳ روز گذشته» are the same
     * arithmetic and two completely different conversations, and a
     * caller handed an absolute number has to work out which one it is.
     *
     * Whole days on both sides. Measured to the end of the last day it
     * came out half a day short and truncated the wrong way — a term
     * that ran out five days ago read as four, which is the sort of
     * error nobody checks and everybody half-remembers.
     */
    public function getDaysLeftAttribute(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->ends_on->startOfDay(), false);
    }

    public function payload(): array
    {
        return [
            'id' => $this->id,
            'plan' => $this->plan,
            'starts_on_label' => AppCalendar::date($this->starts_on),
            'ends_on_label' => AppCalendar::date($this->ends_on),
            'amount_formatted' => $this->amount === null
                ? null
                : Money::format((float) $this->amount),
            'is_current' => $this->is_current,
            'days_left' => $this->days_left,
            'note' => $this->note,
            'cancelled_on_label' => $this->cancelled_at
                ? AppCalendar::date($this->cancelled_at)
                : null,
            'cancellation_reason' => $this->cancellation_reason,
        ];
    }
}
