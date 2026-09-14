<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Model;

/**
 * Money the shop is holding for a buyer, over and above the invoices their
 * payment closed.
 *
 * An invoice settles whole, so a payment that does not land on an invoice
 * boundary leaves a remainder. Rather than teach every report to read a
 * half-settled sale, the remainder waits here and is spent on the next
 * collection before any new money is asked for.
 *
 * The buyer's side of SellerAccountCredit, and the same shape on purpose:
 * two ledgers that answer the same question differently is how they come
 * to disagree.
 */
class CustomerCredit extends Model
{
    use BelongsToBakery, RecordsAudit;

    protected $fillable = [
        'customer_id',
        'user_id',
        'amount',
        'note',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** What the shop is holding for this buyer right now. */
    public static function balanceFor(int $customerId): float
    {
        return round(
            (float) static::query()->where('customer_id', $customerId)->sum('amount'),
            2,
        );
    }

    /**
     * How this row names itself in the trail.
     *
     * The log outlives the record: once the row is gone its id points at
     * nothing, and this sentence is all that is left to argue from.
     */
    public function auditSubject(): ?string
    {
        return trim('اعتبار مشتری '.($this->customer?->name ?? ''));
    }
}
