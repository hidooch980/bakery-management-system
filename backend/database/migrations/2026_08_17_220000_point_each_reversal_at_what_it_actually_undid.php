<?php

use App\Models\InventoryMovement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reversals wired to the wrong movement, and to none at all.
 *
 * `reverses_movement_id` arrived on 2026-08-16 so a quota period could ask
 * whether the cancellation it is looking at belongs to it. The migration
 * that backfilled it matched on item and quantity and took the first
 * candidate — and this shop bakes the same 440 kg batch over and over, so
 * two reversals were wired to movements nine days older, belonging to a
 * dough entry that was never deleted.
 *
 * Two more have no link because they were written before the column
 * existed. Both were found on 2026-08-17 while answering a question about
 * flour consumption, and neither is a stock error: every quantity was
 * given back. It is the link that is wrong, and a wrong link puts a
 * refund in a quota period that did not spend it.
 *
 * The rule this applies, which the quantity match had no way of knowing:
 * **a reversal cannot undo a movement whose record still exists.** Nothing
 * deleted it, so nothing reversed it.
 *
 * This moves no stock. It writes one column.
 */
return new class extends Migration
{
    public function up(): void
    {
        $reversals = InventoryMovement::query()
            ->withoutGlobalScopes()
            ->whereIn('reason', ['production_reversal', 'flour_sale_reversal', 'consignment_return'])
            ->orderBy('id')
            ->get();

        // Movements already spoken for, so two reversals of the same size
        // cannot both claim one original.
        $claimed = $reversals
            ->pluck('reverses_movement_id')
            ->filter(fn ($id) => $id !== null && $this->sourceIsGone($id))
            ->all();

        foreach ($reversals as $reversal) {
            if ($reversal->reverses_movement_id !== null && $this->sourceIsGone($reversal->reverses_movement_id)) {
                continue;
            }

            $match = $this->originalFor($reversal, $claimed);

            if ($match === null) {
                continue;
            }

            $claimed[] = $match->id;

            DB::table('inventory_movements')
                ->where('id', $reversal->id)
                ->update(['reverses_movement_id' => $match->id]);
        }
    }

    /**
     * The movement this reversal undid: same item, same quantity, the
     * other way round, written before it, and belonging to a record that
     * is no longer there.
     */
    private function originalFor(InventoryMovement $reversal, array $claimed): ?InventoryMovement
    {
        return InventoryMovement::query()
            ->withoutGlobalScopes()
            ->where('inventory_item_id', $reversal->inventory_item_id)
            ->where('direction', $reversal->direction === 'in' ? 'out' : 'in')
            ->where('quantity', $reversal->quantity)
            ->where('id', '<', $reversal->id)
            ->whereNotIn('id', $claimed)
            ->whereNotNull('source_type')
            ->orderByDesc('id')
            ->get()
            // Nearest first: a batch cancelled minutes after it was written
            // is a better answer than an identical one from last week.
            ->first(fn (InventoryMovement $m) => $this->sourceIsGone($m->id));
    }

    private function sourceIsGone(int $movementId): bool
    {
        $movement = InventoryMovement::withoutGlobalScopes()->find($movementId);

        if ($movement === null || $movement->source_type === null) {
            return false;
        }

        $class = $movement->source_type;

        return ! class_exists($class)
            || $class::withoutGlobalScopes()->find($movement->source_id) === null;
    }

    /**
     * Not reversed. Putting the wrong links back would be restoring an
     * error, and the right ones are indistinguishable from what a correct
     * backfill would have written in the first place.
     */
    public function down(): void
    {
        //
    }
};
