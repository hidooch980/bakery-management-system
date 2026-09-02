<?php

use App\Models\ChaneEntry;
use App\Models\Sale;
use App\Support\SaleRecorder;
use Illuminate\Database\Migrations\Migration;

/**
 * Undoes the double charge the previous recompute wrote.
 *
 * `2026_09_02_140000` worked every batch's shortfall out again, and got
 * two of them wrong. It walked a batch's sales in id order and wrote the
 * remainder on the first line that could carry it — before reaching a
 * line further down whose shortfall had already been settled. Those
 * loaves are missing and answered for at the same time, so they came off
 * neither figure and were charged twice:
 *
 *     batch #110   1 → 2      (1 already settled)
 *     batch #115  58 → 116    (58 already settled)
 *
 * Batches #140 and #142 were corrected properly by that run and are not
 * touched again here — the recompute is idempotent, so running it a
 * second time leaves a right answer alone.
 *
 * `refreshBatchShortfall()` now takes settled shortfalls off the
 * remainder, with two tests holding it. This applies that rule to what
 * the first run left behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        $corrected = 0;

        ChaneEntry::with('sales')->whereHas('sales')->chunkById(100, function ($batches) use (&$corrected) {
            foreach ($batches as $batch) {
                $before = (int) Sale::where('chane_entry_id', $batch->id)->sum('shortfall_count');

                SaleRecorder::refreshBatchShortfall($batch);

                $after = (int) Sale::where('chane_entry_id', $batch->id)->sum('shortfall_count');

                if ($before !== $after) {
                    $corrected++;
                    echo "  batch #{$batch->id}: کسری {$before} → {$after}\n";
                }
            }
        });

        echo $corrected === 0
            ? "  چیزی برای اصلاح نبود.\n"
            : "  {$corrected} دسته اصلاح شد.\n";
    }

    public function down(): void
    {
        // The figures this replaced were wrong. There is nothing to restore.
    }
};
