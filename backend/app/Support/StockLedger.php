<?php

namespace App\Support;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Model;

/**
 * What a record has actually moved, and one movement to make the
 * warehouse agree with what the record now says.
 *
 * Every model that spends stock has had the same hole in it: it moved the
 * warehouse when the record was created and when it was deleted, and not
 * when it was edited. So correcting a number squared the books and left
 * the real goods somewhere else — the shop's fourth bug of that shape,
 * and the one that cost 400 kg of flour.
 *
 * The rule these models now share is the one `ConsignmentFlour` arrived at
 * first: what a record *should* have moved is a fact about its current
 * state, and the difference from what it *has* moved is posted as a single
 * correcting movement. Nothing is rewritten, so the ledger still says what
 * happened and when.
 *
 * What it has actually moved is read from the ledger rather than
 * recomputed from a formula. The formula can change — this shop's salt and
 * yeast ratios both have — and recomputing an old record against today's
 * ratios would post a correction for a change nobody made.
 */
class StockLedger
{
    /**
     * Net quantity of one item this record has taken out of the store.
     *
     * Negative means it put stock in, which is what a borrowing or a
     * reversal does.
     */
    public static function netMoved(Model $source, int $itemId): float
    {
        $net = 0.0;

        $movements = InventoryMovement::query()
            ->where('source_type', $source::class)
            ->where('source_id', $source->getKey())
            ->where('inventory_item_id', $itemId)
            ->get();

        foreach ($movements as $movement) {
            $net += ($movement->direction === 'out' ? 1 : -1) * (float) $movement->quantity;
        }

        return round($net, 3);
    }

    /**
     * Posts whatever single movement leaves this record having moved
     * `$shouldBeOut` of the item, and answers with the quantity posted.
     *
     * Zero when the ledger already agrees — worth checking, because an
     * edit that does not change the goods must not leave a trail of
     * nought-kilogram movements behind it.
     */
    public static function reconcile(
        Model $source,
        InventoryItem $item,
        float $shouldBeOut,
        string $reason,
        string $note,
        ?int $userId = null,
    ): float {
        $delta = round($shouldBeOut - self::netMoved($source, $item->getKey()), 3);

        if (abs($delta) < 0.001) {
            return 0.0;
        }

        $item->move(
            $delta > 0 ? 'out' : 'in',
            abs($delta),
            $reason,
            $userId,
            $source,
            $note,
        );

        return $delta;
    }
}
