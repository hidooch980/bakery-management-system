<?php

namespace App\Support;

use App\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Model;

/**
 * Puts back whatever stock a record actually moved.
 *
 * The reversal reads the movements on file rather than recomputing them
 * from the formula. That matters for two reasons: the formula may have
 * changed since the record was made, and not every record moved stock at
 * all — an entry created straight in the panel never touched the
 * warehouse, so there is nothing to give back and inventing a reversal
 * would inflate the balance out of nowhere.
 */
class StockReversal
{
    public static function of(Model $source, string $note): void
    {
        $movements = InventoryMovement::query()
            ->where('source_type', $source::class)
            ->where('source_id', $source->getKey())
            ->with('item')
            ->get()
            // Undoing an "out" puts stock back; undoing an "in" takes it
            // away. Doing the giving first means a record whose movements
            // cancel out — borrowed flour that was later handed back — can
            // always be reversed, instead of failing on an interim step
            // that dips below zero on the way to the same final balance.
            ->sortByDesc(fn (InventoryMovement $m) => $m->direction === 'out' ? 1 : 0);

        foreach ($movements as $movement) {
            $movement->item?->move(
                // Undo each one by moving the same quantity the other way.
                $movement->direction === 'out' ? 'in' : 'out',
                (float) $movement->quantity,
                self::reversalReasonFor($movement->reason),
                $movement->user_id,
                null,
                $note,
            );
        }
    }

    private static function reversalReasonFor(string $reason): string
    {
        return match ($reason) {
            'flour_sale' => 'flour_sale_reversal',
            'consignment_in', 'consignment_out' => 'consignment_return',
            default => 'production_reversal',
        };
    }
}
