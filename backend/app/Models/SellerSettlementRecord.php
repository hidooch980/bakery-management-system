<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
use App\Support\AppCalendar;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * One time a seller handed money over.
 *
 * The seller's own request has always left a row; the owner settling
 * somebody at the counter left nothing but a bank movement with a note.
 * That is the common case, and it is the one that had no history and
 * could not be undone.
 *
 * The class is named for the record rather than the act because
 * `SellerSettlement` — the support class that does the settling — already
 * holds the other name, and two things called the same thing in the same
 * namespace is how one of them ends up called by mistake.
 */
class SellerSettlementRecord extends Model
{
    use BelongsToBakery, RecordsAudit;

    protected $table = 'seller_settlements';

    protected $fillable = [
        'user_id',
        'settled_by',
        'settlement_request_id',
        'kind',
        'paid_cash',
        'paid_card',
        'amount',
        'bank_account_id',
        'sale_ids',
        'credit_ids',
        'note',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'paid_cash' => 'decimal:2',
            'paid_card' => 'decimal:2',
            'amount' => 'decimal:2',
            'sale_ids' => 'array',
            'credit_ids' => 'array',
            'reversed_at' => 'datetime',
        ];
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function settledBy()
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function reversedBy()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function scopeStanding($query)
    {
        return $query->whereNull('reversed_at');
    }

    public function getIsReversedAttribute(): bool
    {
        return $this->reversed_at !== null;
    }

    /** A settlement closed sales; a payment left the account open. */
    public function getIsSettlementAttribute(): bool
    {
        return $this->kind === 'settlement';
    }

    public function payload(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'kind_label' => $this->is_settlement ? 'تسویه' : 'پرداخت',
            'seller_name' => $this->seller?->name,
            'settled_by_name' => $this->settledBy?->name,
            'amount' => Money::convert((float) $this->amount),
            'amount_formatted' => Money::format((float) $this->amount),
            'paid_cash_formatted' => Money::format((float) $this->paid_cash),
            'paid_card_formatted' => Money::format((float) $this->paid_card),
            'account' => $this->bankAccount?->title,
            'sale_count' => count($this->sale_ids ?? []),
            'from_request' => $this->settlement_request_id !== null,
            'on' => $this->created_at?->toDateString(),
            'on_label' => AppCalendar::date($this->created_at),
            'note' => $this->note,
            'is_reversed' => $this->is_reversed,
            'reversed_on_label' => $this->reversed_at
                ? AppCalendar::date($this->reversed_at)
                : null,
            'reversed_by_name' => $this->reversedBy?->name,
            'reversal_reason' => $this->reversal_reason,
        ];
    }
}
