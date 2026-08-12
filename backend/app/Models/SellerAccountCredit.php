<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use Illuminate\Database\Eloquent\Model;

/**
 * Money the shop is holding for a seller, over and above the sales their
 * payment closed.
 *
 * A sale settles whole — cash_settled_on is a date, not a part share — so
 * a handover that does not land on a sale boundary leaves a remainder.
 * Rather than teach every report to read a half settled sale, the
 * remainder waits here and is spent on the next settlement before any new
 * money is asked for.
 */
class SellerAccountCredit extends Model
{
    use BelongsToBakery;

    protected $fillable = [
        'user_id',
        'amount',
        'settlement_request_id',
        'note',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function settlementRequest()
    {
        return $this->belongsTo(SettlementRequest::class);
    }

    /** What the shop is holding for this seller right now. */
    public static function balanceFor(int $userId): float
    {
        return round((float) static::query()->where('user_id', $userId)->sum('amount'), 2);
    }
}
