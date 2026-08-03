<?php

namespace App\Support;

use App\Models\Bakery;

/**
 * Turns a number of flour bags into the dough and chane figures the shop
 * expects, using the ratios the admin configured.
 *
 *   flour   = bags × bag weight
 *   water   = flour × water ratio
 *   salt    = flour × salt ratio
 *   yeast   = flour × yeast ratio
 *   dough   = (flour + water + salt + yeast) × (1 − loss ratio)
 *   chane   = dough ÷ per-chane weight
 *
 * Keeping this in one place means the panel, the API and the app can never
 * disagree about how many chane a batch should produce.
 */
class DoughFormula
{
    /** Which yeast a batch was mixed with. Fresh proves faster in the cold. */
    public const DRY = 'dry';
    public const WET = 'wet';

    public const YEAST_LABELS = [
        self::DRY => 'خمیرمایه خشک',
        self::WET => 'خمیرمایه تر',
    ];

    public function __construct(
        public readonly float $bagWeightKg,
        public readonly float $waterRatio,
        public readonly float $saltRatio,
        public readonly float $yeastRatio,
        public readonly float $lossRatio,
        public readonly ?float $normalChaneWeightKg,
        public readonly ?float $naninoChaneWeightKg,
        /** How much more the dough yields once it has proved. */
        public readonly float $proofGainRatio = 0.0,
    ) {}

    public static function fromBakery(?Bakery $bakery = null): self
    {
        $bakery ??= Bakery::query()->first();

        return new self(
            bagWeightKg: (float) ($bakery?->flour_bag_weight_kg ?? 40),
            waterRatio: (float) ($bakery?->water_ratio ?? 0.5),
            saltRatio: (float) ($bakery?->salt_ratio ?? 0.0175),
            yeastRatio: (float) ($bakery?->yeast_ratio ?? 0.005),
            lossRatio: (float) ($bakery?->dough_loss_ratio ?? 0),
            normalChaneWeightKg: $bakery?->normal_chane_weight_kg
                ? (float) $bakery->normal_chane_weight_kg
                : null,
            naninoChaneWeightKg: $bakery?->nanino_chane_weight_kg
                ? (float) $bakery->nanino_chane_weight_kg
                : null,
            proofGainRatio: (float) ($bakery?->proof_gain_ratio ?? 0.115),
        );
    }

    /**
     * What the batch actually yields on the bench.
     *
     * doughKg() is what went into the bowl. The batch is shaped after it
     * has proved, and by then it gives more chane than that raw weight
     * divided by the weight of one — worth up to ninety chane on a batch
     * in a shop that lets it rest properly.
     */
    public function shapeableKg(float $bags): float
    {
        return round($this->doughKg($bags) * (1 + $this->proofGainRatio), 3);
    }

    public static function yeastLabel(?string $type): string
    {
        return self::YEAST_LABELS[$type] ?? self::YEAST_LABELS[self::DRY];
    }

    public function flourKg(float $bags): float
    {
        return round($bags * $this->bagWeightKg, 3);
    }

    public function waterKg(float $bags): float
    {
        return round($this->flourKg($bags) * $this->waterRatio, 3);
    }

    public function saltKg(float $bags): float
    {
        return round($this->flourKg($bags) * $this->saltRatio, 3);
    }

    /** Yeast the batch takes, whichever kind the shop used. */
    public function yeastKg(float $bags): float
    {
        return round($this->flourKg($bags) * $this->yeastRatio, 3);
    }

    /** Total usable dough after the configured handling loss. */
    public function doughKg(float $bags): float
    {
        $raw = $this->flourKg($bags)
            + $this->waterKg($bags)
            + $this->saltKg($bags)
            + $this->yeastKg($bags);

        return round($raw * (1 - $this->lossRatio), 3);
    }

    /** How many normal chane a batch yields, or null if no weight is set. */
    public function normalChaneCount(float $bags): ?int
    {
        if (! $this->normalChaneWeightKg) {
            return null;
        }

        return (int) floor($this->shapeableKg($bags) / $this->normalChaneWeightKg);
    }

    public function naninoChaneCount(float $bags): ?int
    {
        if (! $this->naninoChaneWeightKg) {
            return null;
        }

        return (int) floor($this->shapeableKg($bags) / $this->naninoChaneWeightKg);
    }

    /**
     * Dough weight that a given number of normal chane accounts for. This is
     * what the chane form shows as a read-only figure.
     */
    public function weightForNormalChane(int $count): ?float
    {
        if (! $this->normalChaneWeightKg) {
            return null;
        }

        return round($count * $this->normalChaneWeightKg, 3);
    }

    public function weightForNaninoChane(int $count): ?float
    {
        if (! $this->naninoChaneWeightKg) {
            return null;
        }

        return round($count * $this->naninoChaneWeightKg, 3);
    }

    /**
     * The actual nanino count for a recorded weight. ChaneEntry stores only
     * the nanino weight, not a count, so every place that needs the real
     * figure (as opposed to the what-if equivalent below) derives it the
     * same way through here.
     */
    public function naninoCountForWeight(float $naninoWeightKg): int
    {
        if (! $this->naninoChaneWeightKg) {
            return 0;
        }

        return (int) round($naninoWeightKg / $this->naninoChaneWeightKg);
    }

    /**
     * How many nanino loaves the same dough would have produced, had the
     * normal chane actually baked today been shaped as nanino instead.
     *
     *   equivalent = (normal count × normal weight) ÷ nanino weight
     *
     * This is a what-if comparison, not a count of anything actually made —
     * real nanino output is tracked separately, from its own recorded weight.
     */
    public function naninoEquivalentForNormalCount(int $normalCount): ?int
    {
        if (! $this->normalChaneWeightKg || ! $this->naninoChaneWeightKg) {
            return null;
        }

        $weight = $normalCount * $this->normalChaneWeightKg;

        return (int) floor($weight / $this->naninoChaneWeightKg);
    }

    /** Everything a client needs to compute the same figures locally. */
    public function toArray(float $bags = 1): array
    {
        return [
            'flour_bag_weight_kg' => $this->bagWeightKg,
            'water_ratio' => $this->waterRatio,
            'salt_ratio' => $this->saltRatio,
            'yeast_ratio' => $this->yeastRatio,
            'dough_loss_ratio' => $this->lossRatio,
            'proof_gain_ratio' => $this->proofGainRatio,
            'normal_chane_weight_kg' => $this->normalChaneWeightKg,
            'nanino_chane_weight_kg' => $this->naninoChaneWeightKg,
            'per_bag' => [
                'flour_kg' => $this->flourKg($bags),
                'water_kg' => $this->waterKg($bags),
                'salt_kg' => $this->saltKg($bags),
                'yeast_kg' => $this->yeastKg($bags),
                'dough_kg' => $this->doughKg($bags),
                'shapeable_kg' => $this->shapeableKg($bags),
                'normal_chane_count' => $this->normalChaneCount($bags),
                'nanino_chane_count' => $this->naninoChaneCount($bags),
            ],
        ];
    }
}
