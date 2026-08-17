<?php

use App\Models\ChaneEntry;
use App\Models\ConsignmentFlour;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Support\DoughFormula;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Three records that spent stock and never moved it.
 *
 * An audit of every record that ought to have a stock movement against it —
 * every consignment, every flour sale, every batch of dough, every tray of
 * chane — found three. The rest of the warehouse ties out.
 *
 *   - **Consignment #3, 2 Mordad, 50 sacks lent.** Recorded two days before
 *     the model learned to move stock when a consignment is written, so the
 *     sacks left the shop and the ledger never noticed. The three lendings
 *     after it all moved correctly.
 *
 *   - **Dough #82 and chane #128, 18 Mordad.** My own doing: the migration
 *     that recorded the day the system was down called `DoughEntry::create`
 *     directly. The model only reverses stock on delete — consuming it is
 *     ProductionRecorder's job, and going round it wrote the bake without
 *     the flour, salt and yeast it ate.
 *
 * Together the store is holding 2,400 kg of flour it does not have: 2,000
 * lent out, 400 baked. That is 60 sacks, and it also means the flour quota
 * for those periods reads lower than the shop actually drew.
 *
 * The amounts are worked out by the same formula the app uses rather than
 * restated here, and each movement is dated to the day it belongs to, so
 * the quota periods land where they should. Every movement is tagged back
 * to the record it belongs to — which is what makes the audit that found
 * this able to confirm the fix.
 */
return new class extends Migration
{
    /** 18 Mordad, when the shop baked and the system was down. */
    private const BAKE_DAY = '2026-08-09 16:45:00';

    /** 2 Mordad, when fifty sacks went to a partner bakery. */
    private const LENT_DAY = '2026-08-02 10:50:05';

    public function up(): void
    {
        DB::transaction(function () {
            $this->lentSacks();
            $this->theBakeOnTheEighteenth();
        });
    }

    /**
     * Deliberately not reversible.
     *
     * Reversing would take the shop back to a warehouse that disagrees with
     * its own records. If any of these turn out to be wrong, the honest fix
     * is a correcting movement with a note, not an undo.
     */
    public function down(): void
    {
        // Nothing.
    }

    private function lentSacks(): void
    {
        $record = ConsignmentFlour::withoutGlobalScopes()
            ->where('direction', 'lent')
            ->whereDate('occurred_on', '2026-08-02')
            ->first();

        // Every one of these has to match or this is not the row this was
        // written about — a fresh database, or any install but this shop's.
        if (! $record
            || abs((float) $record->bags - 50) > 0.01
            || $this->alreadyMoved($record)) {
            return;
        }

        // The partner was on the record all along, through customer_id
        // rather than the free-text column — عبدالرئوف درازهی, the same
        // bakery the six sacks went to four days later. Nothing to fill in;
        // only the stock movement was ever missing.
        $this->write(
            InventoryItem::FLOUR,
            (float) $record->amount_kg,
            'consignment_out',
            $record,
            self::LENT_DAY,
            'آرد امانی داده‌شده به '.$record->partner_label
                .' — ثبت با تأخیر، پیش از آنکه سامانه انبار را جابه‌جا کند.',
        );
    }

    private function theBakeOnTheEighteenth(): void
    {
        $dough = DoughEntry::withoutGlobalScopes()
            ->whereDate('created_at', '2026-08-09')
            ->first();

        if (! $dough || abs((float) $dough->bag_count - 10) > 0.01 || $this->alreadyMoved($dough)) {
            return;
        }

        $formula = DoughFormula::fromBakery();
        $bags = (int) $dough->bag_count;
        $note = 'مصرف پخت ۱۸ مرداد — روزی که سامانه قطع بود و با تأخیر ثبت شد.';

        $this->write(InventoryItem::FLOUR, $formula->flourKg($bags), 'production', $dough, self::BAKE_DAY, $note);
        $this->write(InventoryItem::SALT, $formula->saltKg($bags), 'production', $dough, self::BAKE_DAY, $note);

        $yeast = $formula->yeastKg($bags);

        if ($yeast > 0) {
            // Dry, which is what that batch was recorded as.
            $this->write(InventoryItem::YEAST_DRY, $yeast, 'production', $dough, self::BAKE_DAY, $note);
        }

        $chane = ChaneEntry::withoutGlobalScopes()
            ->where('dough_entry_id', $dough->id)
            ->first();

        if ($chane && (float) $chane->spray_flour_kg > 0 && ! $this->alreadyMoved($chane)) {
            $this->write(
                InventoryItem::FLOUR,
                (float) $chane->spray_flour_kg,
                'spray',
                $chane,
                self::BAKE_DAY,
                'آرد پاششی ۱۸ مرداد — همراه همان پخت.',
            );
        }
    }

    private function alreadyMoved($record): bool
    {
        return InventoryMovement::where('source_type', $record::class)
            ->where('source_id', $record->id)
            ->exists();
    }

    /**
     * Written straight rather than through InventoryItem::move(), because
     * that stamps the movement with the moment it runs and these belong to
     * the days they happened on — the flour quota is counted by period, so
     * a movement dated today would land in the wrong one.
     */
    private function write(
        string $key,
        float $quantity,
        string $reason,
        $source,
        string $when,
        string $note,
    ): void {
        if ($quantity <= 0) {
            return;
        }

        InventoryMovement::create([
            'inventory_item_id' => InventoryItem::ofKey($key)->id,
            'direction' => 'out',
            'quantity' => round($quantity, 3),
            'reason' => $reason,
            'user_id' => $source->user_id,
            'source_type' => $source::class,
            'source_id' => $source->id,
            'note' => $note,
        ])->forceFill(['created_at' => $when, 'updated_at' => $when])->save();
    }
};
