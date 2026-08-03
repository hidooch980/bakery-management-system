<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\FlourAllocation;
use App\Models\Sale;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A delivery period's quota, restated as loaves, against what the card
 * reader sold.
 *
 * Nanino is the measure because the reader is wired into it, so its loaf
 * is the one counted outside the shop: a period of 115 sacks at 64 loaves
 * a sack is 7,360 loaves, whatever shape they were actually baked in.
 */
class PeriodBreadReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        // 40kg sacks, 0.6 water, 1kg nanino loaves: 64.6kg of dough a sack,
        // which is 64 whole loaves.
        Bakery::first()->update([
            'flour_bag_weight_kg' => 40,
            'water_ratio' => 0.6,
            'salt_ratio' => 0.015,
            'dough_loss_ratio' => 0,
            // Proving is measured in ProofGainTest; here the
            // formula's own arithmetic is what is under test.
            'proof_gain_ratio' => 0,
            'nanino_chane_weight_kg' => 1,
            'normal_chane_weight_kg' => 0.85,
            'currency' => 'toman',
        ]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function allocationOf(float $bags): FlourAllocation
    {
        $allocation = FlourAllocation::create([
            'month_start' => now()->startOfMonth(),
            'month_label' => Jalali::monthLabel(now()->startOfMonth()) ?? '',
            'total_bags' => $bags,
        ]);

        $allocation->syncPeriods();

        return $allocation->fresh('periods');
    }

    public function test_a_sack_of_flour_comes_to_sixty_four_loaves(): void
    {
        // Three periods of 115 sacks each, so one period is 115 sacks.
        $allocation = $this->allocationOf(345);
        $period = $allocation->periods->first();

        $this->assertSame(115.0, $allocation->bagsForPeriod($period));
        $this->assertSame(7360, $period->allocated_bread_count);
    }

    public function test_the_remainder_is_the_quota_less_what_the_reader_sold(): void
    {
        $allocation = $this->allocationOf(3);
        $period = $allocation->periods->first();

        // One sack a period: 64 loaves.
        $this->assertSame(64, $period->allocated_bread_count);

        $this->sellOnCard(55, within: $period->starts_on);

        $period = $period->fresh();
        $this->assertSame(55, $period->card_bread_count);
        $this->assertSame(9, $period->bread_remainder);
    }

    public function test_only_card_sales_count_towards_the_reader(): void
    {
        $allocation = $this->allocationOf(3);
        $period = $allocation->periods->first();

        $this->sellOnCard(20, within: $period->starts_on);
        $this->sell('cash', 30, within: $period->starts_on);
        $this->sell('charity', 5, within: $period->starts_on);

        $period = $period->fresh();

        // Only the reader's own count is the outside measure.
        $this->assertSame(20, $period->card_bread_count);
        $this->assertSame(44, $period->bread_remainder);
    }

    public function test_sales_outside_the_window_are_not_counted(): void
    {
        $allocation = $this->allocationOf(3);
        $period = $allocation->periods->first();

        $this->sellOnCard(10, within: $period->ends_on->copy()->addDays(3));

        $this->assertSame(0, $period->fresh()->card_bread_count);
    }

    public function test_the_card_turnover_is_reported(): void
    {
        $allocation = $this->allocationOf(3);
        $period = $allocation->periods->first();

        $this->sellOnCard(10, within: $period->starts_on, amount: 250_000);
        $this->sellOnCard(5, within: $period->starts_on, amount: 125_000);

        $this->assertSame(375_000.0, $period->fresh()->card_amount);
    }

    public function test_the_figures_reach_the_api(): void
    {
        $allocation = $this->allocationOf(3);
        $period = $allocation->periods->first();

        $this->sellOnCard(55, within: $period->starts_on);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/flour-allocations/current')
            ->assertOk()
            ->assertJsonPath('data.periods.0.allocated_bread_count', 64)
            ->assertJsonPath('data.periods.0.card_bread_count', 55)
            ->assertJsonPath('data.periods.0.bread_remainder', 9);
    }

    // ---------------------------------------------------------- helpers

    private function sellOnCard(int $breadCount, $within, float $amount = 100_000): void
    {
        $this->sell('card', $breadCount, $within, $amount);
    }

    private function sell(string $type, int $breadCount, $within, float $amount = 100_000): void
    {
        $dough = DoughEntry::create([
            'user_id' => $this->admin->id,
            'bag_count' => 1,
            'status' => 'processed',
        ]);

        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->admin->id,
            'chane_count' => $breadCount,
            'normal_weight_kg' => 0,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'sold',
        ]);

        $sale = Sale::create([
            'chane_entry_id' => $chane->id,
            'user_id' => $this->admin->id,
            'payment_type' => $type,
            'bread_count' => $breadCount,
            'amount' => $amount,
        ]);

        // Sales are stamped by their creation time, so a row that belongs
        // to another day has to be moved there explicitly.
        $sale->forceFill(['created_at' => $within->copy()->addHours(9)])->saveQuietly();
    }
}
