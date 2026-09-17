<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
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
    use BelongsToBakery, RecordsAudit;

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

    /**
     * The same figure for a whole list of sellers, in one query.
     *
     * The accounts page shows every seller at once, and asking per person
     * would put a query on it for each one — the shape this page has
     * already been fixed for once.
     *
     * @param  iterable<int>  $userIds
     * @return array<int, float>
     */
    public static function balancesFor(iterable $userIds): array
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->all();

        if ($ids === []) {
            return [];
        }

        return static::query()
            ->whereIn('user_id', $ids)
            ->selectRaw('user_id, COALESCE(SUM(amount), 0) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id')
            ->map(fn ($total) => round((float) $total, 2))
            ->all();
    }

    /**
     * How this row names itself in the trail.
     *
     * The log outlives the record: once the row is gone its id points at
     * nothing, and this sentence is all that is left to argue from.
     */
    public function auditSubject(): ?string
    {
        return trim('اعتبار فروشنده '.($this->user?->name ?? ''));
    }
}
