<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
use App\Support\AppCalendar;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * A count of the notes in the drawer, against what the books expected.
 *
 * The difference is the only figure here that matters, and it is derived
 * rather than stored: a stored total is a number somebody can edit into
 * agreement, and this record exists precisely to disagree.
 *
 * Correcting the books is a separate decision, taken by the caller and
 * written as an ordinary transaction pointing back at the count. So the
 * history reads: the drawer was short this much, and this is what was
 * done about it — or nothing was, which is also worth knowing.
 */
class CashCount extends Model
{
    use BelongsToBakery, RecordsAudit;

    protected $fillable = [
        'bank_account_id',
        'user_id',
        'counted_amount',
        'expected_amount',
        'counted_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'counted_amount' => 'decimal:2',
            'expected_amount' => 'decimal:2',
            'counted_at' => 'datetime',
        ];
    }

    public function account()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** The adjustment written for this count, if one was. */
    public function adjustment()
    {
        return $this->morphOne(BankTransaction::class, 'source');
    }

    /**
     * Counted minus expected. Positive means more in the drawer than the
     * books know about; negative means the drawer is short.
     */
    public function getDifferenceAttribute(): float
    {
        return round((float) $this->counted_amount - (float) $this->expected_amount, 2);
    }

    /** Whether the gap is worth a sentence, at the scale this shop works in. */
    public function getIsExactAttribute(): bool
    {
        return abs($this->difference) < 0.01;
    }

    /** One line naming this count. */
    public function describe(): string
    {
        $gap = $this->difference;

        return AppCalendar::date($this->counted_at).' — '
            .($this->is_exact
                ? 'خواند'
                : ($gap > 0 ? 'اضافه ' : 'کسری ').Money::format(abs($gap)));
    }
}
