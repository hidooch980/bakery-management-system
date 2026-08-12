<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\Income;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A figure on its own says nothing about whether the shop is doing better
 * or worse. The report now carries the same span immediately before it —
 * the comparison the owner makes in their head anyway.
 */
class PeriodComparisonTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function income(float $amount, string $on): void
    {
        Income::create([
            'user_id' => $this->admin->id,
            'category' => 'other',
            'title' => 'فروش',
            'amount' => $amount,
            'received_on' => $on,
        ]);
    }

    private function report(string $from, string $to): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/reports/financial?from={$from}&to={$to}")
            ->assertOk()
            ->json('data.compared_to_previous');
    }

    public function test_the_window_before_is_the_same_length(): void
    {
        // Seven days asked for, so seven days compared against.
        $comparison = $this->report('2026-08-08', '2026-08-14');

        $this->assertSame(7, $comparison['days']);
        $this->assertSame('2026-08-01', $comparison['from']);
        $this->assertSame('2026-08-07', $comparison['to']);
    }

    public function test_a_better_week_reads_as_up(): void
    {
        $this->income(1_000_000, '2026-08-03'); // previous week
        $this->income(1_500_000, '2026-08-10'); // this week

        $comparison = $this->report('2026-08-08', '2026-08-14');

        $this->assertEquals(1_500_000, $comparison['income']['now']);
        $this->assertEquals(1_000_000, $comparison['income']['before']);
        $this->assertEquals(500_000, $comparison['income']['change']);
        $this->assertEquals(50, $comparison['income']['percent']);
        $this->assertSame('up', $comparison['income']['direction']);
    }

    public function test_a_worse_week_reads_as_down(): void
    {
        $this->income(2_000_000, '2026-08-03');
        $this->income(1_000_000, '2026-08-10');

        $comparison = $this->report('2026-08-08', '2026-08-14');

        $this->assertEquals(-1_000_000, $comparison['income']['change']);
        $this->assertEquals(-50, $comparison['income']['percent']);
        $this->assertSame('down', $comparison['income']['direction']);
    }

    public function test_a_first_week_of_trading_has_no_percentage(): void
    {
        $this->income(1_000_000, '2026-08-10');

        $comparison = $this->report('2026-08-08', '2026-08-14');

        // Nothing before it, so there is no percentage to give. Saying it
        // rose by infinity would be nonsense on the screen.
        $this->assertNull($comparison['income']['percent']);
        $this->assertSame('up', $comparison['income']['direction']);
    }

    public function test_an_unchanged_week_reads_as_flat(): void
    {
        $this->income(1_000_000, '2026-08-03');
        $this->income(1_000_000, '2026-08-10');

        $comparison = $this->report('2026-08-08', '2026-08-14');

        $this->assertSame('flat', $comparison['income']['direction']);
        $this->assertEquals(0, $comparison['income']['change']);
    }

    public function test_one_day_is_compared_against_the_day_before(): void
    {
        $comparison = $this->report('2026-08-12', '2026-08-12');

        $this->assertSame(1, $comparison['days']);
        $this->assertSame('2026-08-11', $comparison['from']);
        $this->assertSame('2026-08-11', $comparison['to']);
    }
}
