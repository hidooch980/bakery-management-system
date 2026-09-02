<?php

use App\Models\ChaneEntry;
use App\Models\Sale;
use App\Support\SaleRecorder;
use Illuminate\Database\Migrations\Migration;

/**
 * Works every batch's shortfall out again from what is on file now.
 *
 * The figure is derived from the batch's bread counts, and those stay
 * editable after a sale is recorded — but nothing recomputed it when they
 * changed. Batch #142 on 1405/06/07 is the one that showed: four lines
 * written together at 14:14, each corrected by hand between 14:20 and
 * 14:22, the counts up by 33 loaves and the shortfall left at 66. The
 * seller was answering for twice what was missing, 3,300,000 rial of it.
 *
 * The hook that keeps this in step from now on is on the Sale model. This
 * is for what was already wrong when it was added, because a stale figure
 * would otherwise sit there until somebody happened to edit that sale
 * again.
 *
 * Nothing is invented: it recomputes from the same rule the recorder uses,
 * and a shortfall already settled is left exactly as it is — that money
 * changed hands and is not ours to revise.
 *
 * Irreversible on purpose. The old figures were wrong; putting them back
 * has no meaning.
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
            ? "  هیچ کسری کهنه‌ای نبود.\n"
            : "  {$corrected} دسته اصلاح شد.\n";
    }

    public function down(): void
    {
        // The figures this replaced were wrong. There is nothing to restore.
    }
};
