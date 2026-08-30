<?php

namespace App\Support;

use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;

/**
 * Records production and moves the stock it consumed.
 *
 * Kneading spends flour, salt and yeast; shaping spends the flour dusted
 * over the bench. Those are the things the shop buys, so those are the
 * things the warehouse counts — the dough between them is mixed and shaped
 * the same day and is never stocked.
 *
 * Both the app and the admin panel come through here so the warehouse says
 * the same thing however the work was entered — the panel used to write the
 * entry and move nothing, which left the books reading differently
 * depending on where someone recorded the batch.
 */
class ProductionRecorder
{
    /**
     * Kneading: flour, salt and yeast out, dough in.
     *
     * The fresh-yeast tub was removed on 1405/06/08 — every batch had been
     * mixed with dry and the choice was one nobody made. The parameter is
     * gone; the column stays, because the batches already recorded carry
     * the value and a report reads it.
     */
    public static function dough(
        int $bags,
        int $userId,
        ?string $note = null,
    ): DoughEntry {
        $formula = DoughFormula::fromBakery();

        return DB::transaction(function () use ($bags, $userId, $note, $formula) {
            $entry = DoughEntry::create([
                'user_id' => $userId,
                'bag_count' => $bags,
                'yeast_type' => DoughFormula::DRY,
                'note' => $note,
                'status' => 'pending',
            ]);

            InventoryItem::ofKey(InventoryItem::FLOUR)->move(
                'out', $formula->flourKg($bags), 'production', $userId, $entry
            );
            InventoryItem::ofKey(InventoryItem::SALT)->move(
                'out', $formula->saltKg($bags), 'production', $userId, $entry
            );

            $yeast = $formula->yeastKg($bags);

            if ($yeast > 0) {
                InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move(
                    'out', $yeast, 'production', $userId, $entry
                );
            }

            // Dough itself is not stocked — see the migration that took it
            // out. What the batch yields is the formula's answer, and the
            // formula is read wherever that answer is needed.

            return $entry;
        });
    }

    /**
     * Shaping: dough out, spray flour out, and the batch marked processed.
     *
     * The dough deducted is the full weight shaped — normal and nanino
     * together. Nanino is a display figure for sales and reports, but the
     * dough shaped into it is physically gone, so deducting only the normal
     * share would leave that dough looking untouched in stock.
     */
    /**
     * Whether a batch can physically yield this much shaped dough.
     *
     * A batch of ten bags makes a known weight of dough and no more. Left
     * unchecked, a count typed one digit too long deducts more than the
     * batch ever held, silently eating into the next batch's dough — and
     * the shop only finds out when the next entry is refused for stock
     * that should have been there.
     *
     * The tolerance is a share of the batch, not a fixed weight. Half a
     * kilo sounds generous until it is measured against a ten-bag batch,
     * where it is seven hundredths of one per cent — tighter than any
     * scale, and tighter than shaping by hand can ever be. A batch that
     * comes out two per cent over is a normal day; a count typed one digit
     * too long is a thousand per cent over, and still caught.
     */
    public const OVERSHOOT_TOLERANCE_RATIO = 0.05;

    /** A floor for tiny batches, where a percentage is worth almost nothing. */
    public const OVERSHOOT_TOLERANCE_MIN_KG = 0.5;

    public static function overshootAllowance(float $availableKg): float
    {
        return max(
            self::OVERSHOOT_TOLERANCE_MIN_KG,
            $availableKg * self::OVERSHOOT_TOLERANCE_RATIO
        );
    }

    public static function problemWithChane(
        DoughEntry $dough,
        float $normalWeightKg,
        float $naninoWeightKg,
    ): ?string {
        // Measured against what the batch yields once proved, not what it
        // weighed in the bowl — the shaping happens after the rest.
        $available = DoughFormula::fromBakery()->shapeableKg((float) $dough->bag_count);
        $shaped = $normalWeightKg + $naninoWeightKg;

        if ($shaped <= $available + self::overshootAllowance($available)) {
            return null;
        }

        // Say by how much, so the difference between a heavy day and a
        // mistyped count is obvious to whoever is standing there.
        return sprintf(
            'وزن چانه‌های ثبت‌شده (%s کیلوگرم) از خمیر این پخت (%s کیلوگرم) بیشتر است — %s درصد اضافه. تعداد چانه را بررسی کنید.',
            number_format($shaped, 2),
            number_format($available, 2),
            number_format($available > 0 ? ($shaped - $available) / $available * 100 : 0, 1),
        );
    }

    public static function chane(
        DoughEntry $dough,
        int $userId,
        float $normalWeightKg,
        float $naninoWeightKg,
        float $sprayFlourKg,
        int $chaneCount,
        ?int $trayCount = null,
        ?array $trayCounts = null,
    ): ChaneEntry {
        return DB::transaction(function () use (
            $dough, $userId, $normalWeightKg, $naninoWeightKg,
            $sprayFlourKg, $chaneCount, $trayCount, $trayCounts
        ) {
            // Guarded here too, not only in the controller, so the panel
            // and any future caller cannot get round it.
            if ($problem = self::problemWithChane($dough, $normalWeightKg, $naninoWeightKg)) {
                throw new \RuntimeException($problem);
            }

            $entry = ChaneEntry::create([
                'dough_entry_id' => $dough->id,
                'user_id' => $userId,
                'chane_count' => $chaneCount,
                'tray_count' => $trayCount,
                'tray_counts' => $trayCounts,
                'normal_weight_kg' => $normalWeightKg,
                'nanino_weight_kg' => $naninoWeightKg,
                'spray_flour_kg' => $sprayFlourKg,
                'status' => 'pending',
            ]);

            $dough->update(['status' => 'processed']);

            if ($sprayFlourKg > 0) {
                // Only the warehouse is written. The separate flour ledger
                // this used to mirror into received spray flour and nothing
                // else, so it drifted negative while the warehouse stayed
                // right; the flour endpoints now read the warehouse instead.
                InventoryItem::ofKey(InventoryItem::FLOUR)->move(
                    'out', $sprayFlourKg, 'spray', $userId, $entry
                );
            }

            return $entry;
        });
    }
}
