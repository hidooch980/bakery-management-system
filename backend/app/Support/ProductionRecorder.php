<?php

namespace App\Support;

use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\FlourStockMovement;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;

/**
 * Records production and moves the stock it consumed.
 *
 * Kneading and shaping are physical: flour and salt leave the store, dough
 * appears and is then spent. Both the app and the admin panel come through
 * here so the warehouse says the same thing however the work was entered —
 * the panel used to write the entry and move nothing, which left the books
 * reading differently depending on where someone recorded the batch.
 */
class ProductionRecorder
{
    /** Kneading: flour and salt out, dough in. */
    public static function dough(int $bags, int $userId, ?string $note = null): DoughEntry
    {
        $formula = DoughFormula::fromBakery();

        return DB::transaction(function () use ($bags, $userId, $note, $formula) {
            $entry = DoughEntry::create([
                'user_id' => $userId,
                'bag_count' => $bags,
                'note' => $note,
                'status' => 'pending',
            ]);

            InventoryItem::ofKey(InventoryItem::FLOUR)->move(
                'out', $formula->flourKg($bags), 'production', $userId, $entry
            );
            InventoryItem::ofKey(InventoryItem::SALT)->move(
                'out', $formula->saltKg($bags), 'production', $userId, $entry
            );
            InventoryItem::ofKey(InventoryItem::DOUGH)->move(
                'in', $formula->doughKg($bags), 'production', $userId, $entry
            );

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
     * A small tolerance is allowed: the scale and the formula will never
     * agree to the gram.
     */
    public const OVERSHOOT_TOLERANCE_KG = 0.5;

    public static function problemWithChane(
        DoughEntry $dough,
        float $normalWeightKg,
        float $naninoWeightKg,
    ): ?string {
        $available = DoughFormula::fromBakery()->doughKg((float) $dough->bag_count);
        $shaped = $normalWeightKg + $naninoWeightKg;

        if ($shaped <= $available + self::OVERSHOOT_TOLERANCE_KG) {
            return null;
        }

        return sprintf(
            'وزن چانه‌های ثبت‌شده (%s کیلوگرم) از خمیر این پخت (%s کیلوگرم) بیشتر است. تعداد چانه را بررسی کنید.',
            number_format($shaped, 2),
            number_format($available, 2),
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
                // The legacy per-entry flour ledger still has readers, so
                // it is written alongside the warehouse movement.
                FlourStockMovement::create([
                    'user_id' => $userId,
                    'type' => 'out',
                    'amount_kg' => $sprayFlourKg,
                    'note' => "آرد پاششی چانه #{$entry->id}",
                ]);

                InventoryItem::ofKey(InventoryItem::FLOUR)->move(
                    'out', $sprayFlourKg, 'spray', $userId, $entry
                );
            }

            InventoryItem::ofKey(InventoryItem::DOUGH)->move(
                'out', $normalWeightKg + $naninoWeightKg, 'production', $userId, $entry
            );

            return $entry;
        });
    }
}
