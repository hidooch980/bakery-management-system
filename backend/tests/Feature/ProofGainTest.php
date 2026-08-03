<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\DoughEntry;
use App\Support\DoughFormula;
use App\Support\ProductionRecorder;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dough yields more once it has rested.
 *
 * The formula adds up what goes into the bowl, but the batch is shaped
 * after it has proved — and by then a sack that weighs out at eighty
 * chane gives up to ninety. Measuring the shaping against the mixing
 * weight refused a normal day's work.
 */
class ProofGainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'flour_bag_weight_kg' => 40,
            'water_ratio' => 0.7,
            'salt_ratio' => 0.016,
            'yeast_ratio' => 0.004,
            'dough_loss_ratio' => 0,
            'proof_gain_ratio' => 0.115,
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
        ]);
    }

    public function test_a_sack_weighs_out_at_eighty_chane_before_it_rests(): void
    {
        $formula = DoughFormula::fromBakery();

        // 40 flour + 28 water + 0.64 salt + 0.16 yeast.
        $this->assertSame(68.8, $formula->doughKg(1));
        $this->assertSame(80, (int) floor($formula->doughKg(1) / 0.85));
    }

    public function test_and_gives_ninety_once_it_has(): void
    {
        $formula = DoughFormula::fromBakery();

        $this->assertSame(90, $formula->normalChaneCount(1));
    }

    public function test_a_normal_day_is_no_longer_refused(): void
    {
        $batch = new DoughEntry(['bag_count' => 11]);

        // 908 chane at 0.85kg — 82.5 a sack, inside what proving gives.
        $this->assertNull(ProductionRecorder::problemWithChane($batch, 771.80, 0.0));
    }

    public function test_a_count_typed_one_digit_too_long_is_still_caught(): void
    {
        $batch = new DoughEntry(['bag_count' => 11]);

        // 9,080 chane: the guard exists for exactly this.
        $problem = ProductionRecorder::problemWithChane($batch, 7718.0, 0.0);

        $this->assertNotNull($problem);
        $this->assertStringContainsString('درصد اضافه', $problem);
    }
}
