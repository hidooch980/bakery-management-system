<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\DoughFormula;
use App\Support\StaffYield;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Yield per sack, per bench — and what it refuses to say.
 *
 * «هدف در برابر واقعی» has been the named gap in clause 5 since the first
 * audit. Everything it needs was already recorded; what it needed was to
 * be fair, because this is a figure that gets read as a judgement about a
 * person.
 *
 * Most of these tests are about the refusals.
 */
class WhatEachBenchGetsOutOfASackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first();

        // Plenty of flour, so nothing here fails for want of stock.
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 100_000, 'purchase');
    }

    private function shaper(string $name): User
    {
        $user = User::factory()->create(['is_active' => true, 'name' => $name]);
        $user->assignRole('chane_gir');

        return $user;
    }

    /** A batch of [bags] sacks shaped by [user] into [chane] normal chane. */
    private function batch(User $user, float $bags, int $chane, float $naninoKg = 0, ?User $second = null): DoughEntry
    {
        $dough = DoughEntry::create([
            'user_id' => $user->id,
            'bag_count' => $bags,
            'status' => 'shaped',
        ]);

        $formula = DoughFormula::fromBakery();

        ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $user->id,
            'chane_count' => $chane,
            'normal_weight_kg' => $chane * ($formula->normalChaneWeightKg ?? 0.85),
            'nanino_weight_kg' => $naninoKg,
            'spray_flour_kg' => 0,
        ]);

        if ($second) {
            ChaneEntry::create([
                'dough_entry_id' => $dough->id,
                'user_id' => $second->id,
                'chane_count' => $chane,
                'normal_weight_kg' => $chane * ($formula->normalChaneWeightKg ?? 0.85),
                'nanino_weight_kg' => 0,
                'spray_flour_kg' => 0,
            ]);
        }

        return $dough;
    }

    private function rows(): Collection
    {
        return StaffYield::between(now()->subDays(30), now());
    }

    public function test_it_reports_what_a_bench_got_out_of_a_sack_against_the_formula(): void
    {
        $expected = DoughFormula::fromBakery()->normalChaneCount(1);

        $ali = $this->shaper('علی');
        $this->batch($ali, bags: 30, chane: (int) ($expected * 30));

        $row = $this->rows()->firstWhere('user', 'علی');

        $this->assertNotNull($row);
        $this->assertSame($expected, $row['expectedPerBag']);
        $this->assertEqualsWithDelta($expected, $row['perBag'], 1.0);
        $this->assertFalse($row['isLow']);
        $this->assertSame(30.0, $row['bags']);
    }

    public function test_a_bench_well_under_the_formula_is_marked(): void
    {
        $expected = DoughFormula::fromBakery()->normalChaneCount(1);

        $reza = $this->shaper('رضا');
        // Three quarters of the formula, over a real sample.
        $this->batch($reza, bags: 40, chane: (int) ($expected * 40 * 0.75));

        $this->assertTrue($this->rows()->firstWhere('user', 'رضا')['isLow']);
    }

    /**
     * The rule that costs the most coverage and is the reason the figure
     * can be trusted at all. Nothing records how many of a shared batch's
     * sacks each person worked, and splitting them by output would make
     * everyone's yield identical by construction.
     */
    public function test_a_batch_two_people_shaped_counts_for_neither(): void
    {
        $expected = DoughFormula::fromBakery()->normalChaneCount(1);

        $ali = $this->shaper('علی');
        $sara = $this->shaper('سارا');

        $this->batch($ali, bags: 40, chane: (int) ($expected * 40), second: $sara);

        $this->assertTrue($this->rows()->isEmpty());
    }

    /** One morning is not a record. */
    public function test_too_small_a_sample_is_not_reported_at_all(): void
    {
        $expected = DoughFormula::fromBakery()->normalChaneCount(1);

        $kam = $this->shaper('کم‌کار');
        $this->batch($kam, bags: StaffYield::MIN_BAGS - 5, chane: (int) ($expected * 5));

        $this->assertNull($this->rows()->firstWhere('user', 'کم‌کار'));
    }

    /**
     * A bench put on the small loaf yields fewer chane for the same flour.
     * Counting that as poor work would punish whoever was moved there.
     */
    public function test_a_bench_on_nanino_is_not_read_as_a_bench_working_badly(): void
    {
        $formula = DoughFormula::fromBakery();
        $expected = $formula->normalChaneCount(1);
        $weight = $formula->normalChaneWeightKg;

        $nano = $this->shaper('نانینوکار');

        // Half the output as normal chane, half the same flour as nanino.
        $half = (int) ($expected * 30 / 2);
        $this->batch($nano, bags: 30, chane: $half, naninoKg: $half * $weight);

        $row = $this->rows()->firstWhere('user', 'نانینوکار');

        $this->assertFalse(
            $row['isLow'],
            'nanino carried across at its own weight should read as a full bench',
        );
    }

    /**
     * The rules travel with the rows. A figure about a person read without
     * them is read as a verdict, and «چرا اسم فلانی نیست» should be
     * answered on the same screen that raises the question.
     */
    public function test_the_rules_reach_the_handset_with_the_rows(): void
    {
        $expected = DoughFormula::fromBakery()->normalChaneCount(1);

        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole('admin');

        $ali = $this->shaper('علی');
        $this->batch($ali, bags: 30, chane: (int) ($expected * 30));

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/reports/staff-yield')
            ->assertOk()
            ->assertJsonPath('data.min_bags', (int) StaffYield::MIN_BAGS)
            ->assertJsonPath('data.rows.0.user', 'علی')
            ->assertJsonStructure(['data' => ['note', 'from_jalali', 'rows' => [['perBag', 'expectedPerBag', 'bags', 'batches', 'isLow']]]]);
    }

    public function test_a_seller_cannot_read_what_the_benches_yield(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/reports/staff-yield')
            ->assertForbidden();
    }

    public function test_the_sample_size_travels_with_the_figure(): void
    {
        $expected = DoughFormula::fromBakery()->normalChaneCount(1);

        $ali = $this->shaper('علی');
        $this->batch($ali, bags: 20, chane: (int) ($expected * 20));
        $this->batch($ali, bags: 15, chane: (int) ($expected * 15));

        $row = $this->rows()->firstWhere('user', 'علی');

        $this->assertSame(2, $row['batches']);
        $this->assertSame(35.0, $row['bags']);
    }
}
