<?php

use App\Models\InventoryMovement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reversal that knows what it reverses.
 *
 * Flour given back when an entry is deleted was never really consumed, and
 * the quota period already nets those off — but it matched them by the
 * reversal's own date. A batch recorded on the 24th and cancelled on the
 * 25th therefore left its consumption inside the period and put its refund
 * in the next one, so the period stayed over quota for work that no longer
 * exists. Exactly that happened when the two double-tapped batches of 24
 * Mordad were taken back: 1,040 kg returned to the store and the period
 * still read 4,678 kg against a 4,573 kg allowance.
 *
 * The reversal keeps its own timestamp, because it did happen when it
 * happened. What it gains is a pointer to the movement it undoes, so a
 * period can ask "was the thing this cancels mine?" rather than "did the
 * cancelling happen while I was open?".
 *
 * Backfills the two reversals already on file by matching quantity, item
 * and direction against the movements of the batches they came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('reverses_movement_id')
                ->nullable()
                ->after('source_id')
                ->constrained('inventory_movements')
                ->nullOnDelete();
        });

        // The two from «ابطال ثبت خمیر» on 2026-08-16, each undoing a 520 kg
        // production draw from 2026-08-15. Paired oldest to oldest so the
        // two identical amounts do not both claim the same original.
        $reversals = InventoryMovement::where('reason', 'production_reversal')
            ->whereNull('reverses_movement_id')
            ->orderBy('id')
            ->get();

        foreach ($reversals as $reversal) {
            $original = InventoryMovement::where('inventory_item_id', $reversal->inventory_item_id)
                ->where('direction', $reversal->direction === 'in' ? 'out' : 'in')
                ->where('quantity', $reversal->quantity)
                ->where('reason', 'production')
                ->whereNotIn('id', InventoryMovement::whereNotNull('reverses_movement_id')
                    ->pluck('reverses_movement_id'))
                ->where('created_at', '<', $reversal->created_at)
                ->orderBy('id')
                ->first();

            if ($original) {
                $reversal->update(['reverses_movement_id' => $original->id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reverses_movement_id');
        });
    }
};
