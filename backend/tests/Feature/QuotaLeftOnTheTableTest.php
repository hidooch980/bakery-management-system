<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\FlourAllocation;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\IssueScanner;
use App\Support\Jalali;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A period's quota going largely unused with the period nearly over.
 *
 * The scanner already says something when the shop uses *more* than its
 * ration. Using much less is the other way to lose it: the allowance does
 * not roll forward.
 *
 * «Used» is flour that went into production — how every other quota
 * figure here is counted. The first version of these tests moved flour
 * *in* and expected that to register; it does not, and two of them failed
 * for that reason rather than for anything about the check.
 *
 * The hard part is not noticing but not crying wolf. Baking is uneven
 * across a fortnight, so «behind» on day two is normal and «behind» on
 * day eight is not.
 */
class QuotaLeftOnTheTableTest extends TestCase
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
     * A period running now, with `$used` of `$allocated` kg already
     * drawn, and `$elapsed` of its days gone.
     */
    private function period(float $allocatedKg, float $usedKg, int $elapsed, int $length = 10): void
    {
        [$monthStart] = Jalali::currentMonthRange();

        $allocation = FlourAllocation::create([
            'month_start' => $monthStart,
            'month_label' => Jalali::monthLabel($monthStart) ?? '',
            'total_bags' => 0,
        ]);

        $starts = now()->copy()->subDays($elapsed - 1)->startOfDay();

        $period = $allocation->periods()->create([
            'period_number' => 1,
            'label' => 'دورهٔ آزمایشی',
            'starts_on' => $starts,
            'ends_on' => $starts->copy()->addDays($length - 1),
            'allocated_kg' => $allocatedKg,
        ]);

        if ($usedKg > 0) {
            // Out, with reason «production». A period's used_kg counts
            // flour that went into bread — an «in» of any reason is not
            // consumption, which is what the first run of these tests got
            // wrong and why two of them failed.
            $item = InventoryItem::ofKey(InventoryItem::FLOUR);
            $item->move('in', $usedKg, 'purchase');
            $item->move('out', $usedKg, 'production');

            DB::table('inventory_movements')
                ->where('inventory_item_id', $item->id)
                ->update(['created_at' => $period->starts_on]);
        }
    }

    private function issues(): array
    {
        return (new IssueScanner)->scan()
            ->filter(fn ($i) => str_starts_with($i->key, 'quota-unlifted'))
            ->values()
            ->all();
    }

    public function test_being_behind_early_in_the_period_says_nothing(): void
    {
        // Day two of ten, nothing baked yet. Normal.
        $this->period(allocatedKg: 4000, usedKg: 0, elapsed: 2);

        $this->assertSame([], $this->issues());
    }

    public function test_past_halfway_with_most_of_it_unlifted_is_flagged(): void
    {
        $this->period(allocatedKg: 4000, usedKg: 500, elapsed: 8);

        $issues = $this->issues();

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('عقب است', $issues[0]->title);
    }

    public function test_a_period_drawn_down_on_schedule_says_nothing(): void
    {
        // Eight days of ten gone, four fifths used. Nothing wrong here,
        // and a warning would teach the owner to ignore the page.
        $this->period(allocatedKg: 4000, usedKg: 3200, elapsed: 8);

        $this->assertSame([], $this->issues());
    }

    public function test_a_fully_drawn_period_says_nothing(): void
    {
        $this->period(allocatedKg: 4000, usedKg: 4000, elapsed: 9);

        $this->assertSame([], $this->issues());
    }

    public function test_a_period_that_has_not_started_says_nothing(): void
    {
        // elapsed 0 puts the start in the future.
        $this->period(allocatedKg: 4000, usedKg: 0, elapsed: 0);

        $this->assertSame([], $this->issues());
    }

    public function test_it_says_how_much_and_how_long_is_left(): void
    {
        $this->period(allocatedKg: 4000, usedKg: 0, elapsed: 8, length: 10);

        $detail = $this->issues()[0]->detail;

        // Sacks, because that is what the shop orders in, and the days
        // remaining, because that is what makes it urgent or not.
        $this->assertStringContainsString('کیسه', $detail);
        $this->assertStringContainsString('2 روز', $detail);
    }

    public function test_a_shop_with_no_quota_recorded_is_not_an_issue(): void
    {
        Bakery::first();

        $this->assertSame([], $this->issues());
    }
}
