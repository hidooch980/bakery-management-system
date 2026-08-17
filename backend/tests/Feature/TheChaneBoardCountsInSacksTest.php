<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «هر ارد = 64 چانه نانینو» — the owner, 2026-08-17.
 *
 * The board's «چانه نانینو (نمایشی)» box used to show the count of nanino
 * actually shaped. This shop shapes nanino about one day in twenty, so the
 * box read a bare zero every other day and the owner said, correctly, that
 * it never changes.
 *
 * But nanino is not really a thing this shop makes — it is the unit the
 * national system counts its output in, and every sack of flour is worth 64
 * of them whatever shape the bread leaves in. That figure moves every day,
 * and it is the one the box is for. The word on the box has been «نمایشی»
 * all along.
 */
class TheChaneBoardCountsInSacksTest extends TestCase
{
    use RefreshDatabase;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'flour_bag_weight_kg' => 40,
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
            'nanino_per_bag' => 64,
        ]);

        $this->baker = User::factory()->create(['is_active' => true]);
        $this->baker->assignRole('admin');
    }

    /** A day's baking: bags kneaded, then shaped. */
    private function bake(int $bags, int $normal, int $nanino = 0): void
    {
        $dough = DoughEntry::create([
            'user_id' => $this->baker->id,
            'bag_count' => $bags,
        ]);

        ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->baker->id,
            'chane_count' => $normal,
            'normal_weight_kg' => $normal * 0.85,
            'nanino_weight_kg' => $nanino * 1.0,
            'spray_flour_kg' => 5,
            'status' => 'sold',
        ]);
    }

    private function board(): array
    {
        Sanctum::actingAs($this->baker);

        return $this->getJson('/api/v1/chane-board')->assertOk()->json('data');
    }

    public function test_a_sack_is_sixty_four_nanino(): void
    {
        $this->bake(bags: 1, normal: 76);

        $this->assertSame(64, $this->board()['dough_today']['as_nanino_count']);
    }

    public function test_ten_sacks_are_six_hundred_and_forty(): void
    {
        $this->bake(bags: 10, normal: 764);

        $this->assertSame(640, $this->board()['dough_today']['as_nanino_count']);
    }

    public function test_the_rate_itself_is_sent(): void
    {
        $this->bake(bags: 10, normal: 764);

        // The card shows its working — «10 کیسه × 64» — so the owner can
        // check it against the number he already knows.
        $this->assertSame(64, $this->board()['dough_today']['nanino_per_bag']);
    }

    public function test_the_figure_moves_with_the_day(): void
    {
        $this->bake(bags: 10, normal: 764);
        $this->assertSame(640, $this->board()['dough_today']['as_nanino_count']);

        $this->bake(bags: 4, normal: 300);

        // This is the whole complaint: the box the owner reads has to
        // change when the day changes. The shaped count does not — it is
        // zero on nineteen days out of twenty.
        $this->assertSame(896, $this->board()['dough_today']['as_nanino_count']);
    }

    public function test_a_shop_that_changed_the_rate_gets_its_own(): void
    {
        Bakery::first()->update(['nanino_per_bag' => 70]);

        $this->bake(bags: 10, normal: 764);

        $board = $this->board();

        $this->assertSame(70, $board['dough_today']['nanino_per_bag']);
        $this->assertSame(700, $board['dough_today']['as_nanino_count']);
    }

    public function test_nanino_actually_shaped_is_still_reported(): void
    {
        // 23 Mordad was a real day: 762 normal and 105 nanino out of the
        // same dough. On a day like that the split between the two systems
        // is genuine and the card shows it.
        $this->bake(bags: 10, normal: 762, nanino: 105);

        $today = $this->board()['today'];

        $this->assertSame(105, $today['nanino_count']);
        $this->assertSame(762, $today['normal_count']);
    }

    public function test_a_day_with_no_nanino_shaped_says_zero_for_that(): void
    {
        $this->bake(bags: 10, normal: 764);

        $today = $this->board()['today'];

        // Zero is the honest figure for what was shaped. What changed is
        // which of the two numbers the box leads with.
        $this->assertSame(0, $today['nanino_count']);
        $this->assertSame(764, $today['normal_count']);
    }

    public function test_dough_kneaded_but_not_yet_shaped_still_counts(): void
    {
        DoughEntry::create(['user_id' => $this->baker->id, 'bag_count' => 6]);

        // The sacks are what the system counts, and they were opened
        // whether or not the bread is out of the oven yet. A board that
        // waited for shaping would read zero all morning.
        $this->assertSame(384, $this->board()['dough_today']['as_nanino_count']);
    }
}
