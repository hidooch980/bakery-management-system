<?php

use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 24 Mordad was recorded three times and baked once.
 *
 * The seller pressed «ثبت خمیر» at 12:18, again at 12:51 and again at
 * 12:53 — the same thirteen bags each time. Only the last was ever shaped,
 * into 989 chane, and the day sold 959 loaves: one batch's worth, not
 * three.
 *
 * Recording dough takes flour, salt and yeast out of the store the moment
 * it is entered, so the two that were never shaped spent 1,040 kg of flour
 * — 26 bags, about 12,480,000 Rial — that never left the sack. That is
 * also the whole of the quota overrun the shop was showing for the 15th to
 * the 24th: 4,678 kg against a 4,573 kg allowance. Take these back and the
 * period reads roughly 3,638 kg, comfortably inside it.
 *
 * Deleting is the right instrument here and it is not destructive: the
 * DoughEntry model answers a delete by writing a reversing stock movement
 * beside each original, labelled «ابطال ثبت خمیر». What happened stays on
 * the record; what it took comes back.
 *
 * The app now refuses an identical batch minutes after the last one, so
 * the same double tap cannot spend the same flour twice again.
 */
return new class extends Migration
{
    /** Both untouched: no chane, no note, nothing else hanging off them. */
    private const ENTRIES = [77, 80];

    private const WHEN = '2026-08-15';

    private const BAGS = 13;

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::ENTRIES as $id) {
                $entry = DoughEntry::withoutGlobalScopes()->find($id);

                // Every one of these has to match, or this is not the row
                // this migration was written about — a fresh database, or
                // any install that is not this shop's.
                if (! $entry
                    || $entry->bag_count !== self::BAGS
                    || $entry->status !== 'pending'
                    || $entry->created_at->toDateString() !== self::WHEN
                    || ChaneEntry::withoutGlobalScopes()->where('dough_entry_id', $id)->exists()) {
                    continue;
                }

                // The reversing movements are written by the model's own
                // deleted hook; nothing to undo by hand.
                $entry->delete();
            }
        });
    }

    /**
     * Deliberately not reversible.
     *
     * Recreating the rows would spend the flour a second time, and the
     * reversals already on file would still be sitting beside the first
     * spend. If these turn out to have been real batches, the honest fix is
     * to record them as new dough with a note, not to resurrect these.
     */
    public function down(): void
    {
        // Nothing.
    }
};
