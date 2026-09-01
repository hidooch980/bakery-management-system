<?php

namespace Tests\Feature;

use App\Models\FlourAllocation;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\FlourQuota;
use App\Support\IssueScanner;
use App\Support\Jalali;
use App\Support\SystemIssue;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Flour quota is a running balance, not a box that empties on a date.
 *
 * «خیلی می‌مونه، انتقال میشن پشت سر هم» — what a period does not lift
 * rolls into the next one, and what a month does not lift rolls into the
 * month after. It never burns.
 *
 * This replaces QuotaLeftOnTheTableTest, which asserted the opposite:
 * that an unlifted period was about to be lost. Every test in it passed,
 * and the behaviour it pinned was wrong — the shop was being told to
 * push 61 sacks through the oven in four days to save quota that was
 * never at risk.
 *
 * The old assumption was wrong in both directions at once, which is why
 * the overrun check moved too: a period drawing on what earlier periods
 * left behind was going to be called «over quota» while the shop was
 * comfortably inside its entitlement.
 */
class QuotaCarriesForwardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        $this->actingAs($user);
    }

    /**
     * A period that started `$startedDaysAgo` ago, with `$usedKg` of its
     * `$allocatedKg` drawn.
     */
    private function period(
        float $allocatedKg,
        float $usedKg,
        int $startedDaysAgo,
        int $length = 10,
        float $carryoverBags = 0,
    ): FlourAllocation {
        // One allocation per month, and the table enforces it — so periods
        // asked for in the same month join the one that is already there.
        [$monthStart] = Jalali::currentMonthRange();

        $allocation = FlourAllocation::firstOrCreate(
            ['month_start' => $monthStart],
            [
                'month_label' => Jalali::monthLabel($monthStart) ?? '',
                'total_bags' => 0,
                'carryover_bags' => $carryoverBags,
            ],
        );

        $starts = now()->copy()->subDays($startedDaysAgo - 1)->startOfDay();

        $period = $allocation->periods()->create([
            'period_number' => $allocation->periods()->count() + 1,
            'label' => 'دورهٔ آزمایشی '.($allocation->periods()->count() + 1),
            'starts_on' => $starts,
            'ends_on' => $starts->copy()->addDays($length - 1),
            'allocated_kg' => $allocatedKg,
        ]);

        if ($usedKg > 0) {
            // «Used» is flour that went into bread. An «in» of any reason
            // is not consumption.
            $item = InventoryItem::ofKey(InventoryItem::FLOUR);

            // Only the rows this call adds get back-dated. Moving every
            // movement to this period would drag an earlier period's
            // consumption forward with it and quietly wreck the sum.
            $before = (int) DB::table('inventory_movements')->max('id');

            $item->move('in', $usedKg, 'purchase');
            $item->move('out', $usedKg, 'production');

            DB::table('inventory_movements')
                ->where('id', '>', $before)
                ->update(['created_at' => $period->starts_on]);
        }

        return $allocation->refresh();
    }

    /** @return array<int, SystemIssue> */
    private function quotaIssues(): array
    {
        return (new IssueScanner)->scan()
            ->filter(fn ($i) => str_starts_with($i->key, 'quota-'))
            ->values()
            ->all();
    }

    public function test_a_period_mostly_unlifted_is_no_longer_chased(): void
    {
        // The shop's own case on 1405/06/10: 61 sacks unlifted with four
        // days to go, reported as something to hurry. Nothing is at risk.
        $this->period(allocatedKg: 4546.7, usedKg: 2105.0, startedDaysAgo: 7);

        $this->assertSame([], $this->quotaIssues());
    }

    public function test_what_is_unlifted_stays_on_the_balance(): void
    {
        $this->period(allocatedKg: 4546.7, usedKg: 2105.0, startedDaysAgo: 7);

        $balance = FlourQuota::balance();

        $this->assertEqualsWithDelta(4546.7, $balance['allocated'], 0.01);
        $this->assertEqualsWithDelta(2105.0, $balance['used'], 0.01);
        // Still the shop's to take, tomorrow or next month.
        $this->assertEqualsWithDelta(2441.7, $balance['remaining'], 0.01);
    }

    public function test_a_period_that_has_not_started_is_not_entitlement_yet(): void
    {
        // Next week's ration is not money in the account.
        $this->period(allocatedKg: 4000.0, usedKg: 0.0, startedDaysAgo: -6);

        $this->assertSame(0.0, FlourQuota::balance()['allocated']);
    }

    public function test_drawing_more_than_one_period_holds_is_not_an_overrun(): void
    {
        // 4000 allocated, 4600 used — over this period's own slice. But
        // an earlier period left 1000 behind, so the shop is well inside
        // what it is entitled to and nothing should be said.
        $this->period(allocatedKg: 3000.0, usedKg: 2000.0, startedDaysAgo: 30);
        $this->period(allocatedKg: 4000.0, usedKg: 4600.0, startedDaysAgo: 7);

        $this->assertEqualsWithDelta(400.0, FlourQuota::remainingKg(), 0.01);
        $this->assertSame([], $this->quotaIssues());
    }

    public function test_the_shop_is_only_over_when_the_whole_account_is(): void
    {
        $this->period(allocatedKg: 3000.0, usedKg: 2000.0, startedDaysAgo: 30);
        $this->period(allocatedKg: 4000.0, usedKg: 5500.0, startedDaysAgo: 7);

        $issues = $this->quotaIssues();

        $this->assertCount(1, $issues);
        $this->assertSame('quota-over', $issues[0]->key);
        // 7000 entitled, 7500 used.
        $this->assertEqualsWithDelta(500.0, $issues[0]->magnitude, 0.01);
        $this->assertStringContainsString('انباشته', $issues[0]->detail);
    }

    public function test_it_is_said_once_about_the_account_not_once_per_period(): void
    {
        $this->period(allocatedKg: 1000.0, usedKg: 2000.0, startedDaysAgo: 30);
        $this->period(allocatedKg: 1000.0, usedKg: 2000.0, startedDaysAgo: 7);

        // Two periods both over their own slice, one thing gone wrong.
        $this->assertCount(1, $this->quotaIssues());
    }

    public function test_a_hand_typed_carryover_is_not_counted_twice(): void
    {
        // A month's periods are cut from total + carryover_bags. The
        // balance already carries last month's leftover forward, so
        // counting the typed figure as well would credit the same flour
        // twice. It has been zero in every real record; this keeps it
        // harmless if it ever is not.
        $bagWeight = 40.0;
        $this->period(
            allocatedKg: 4000.0,
            usedKg: 0.0,
            startedDaysAgo: 7,
            carryoverBags: 10,
        );

        $this->assertEqualsWithDelta(
            4000.0 - (10 * $bagWeight),
            FlourQuota::balance()['allocated'],
            0.01,
        );
    }

    public function test_a_shop_with_no_quota_recorded_says_nothing(): void
    {
        $this->assertSame([], $this->quotaIssues());
        $this->assertSame(0.0, FlourQuota::balance()['remaining']);
    }
}
