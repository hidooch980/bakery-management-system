<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\Holiday;
use App\Models\StaffAdjustment;
use App\Models\User;
use App\Models\WorkStart;
use App\Support\LateDeduction;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * «On a day the shop is closed there is nothing to be late for.»
 *
 * That sentence is the docblock on `todayBoard`, and the board honours
 * it: on a holiday it reports no deadline, no countdown and nothing
 * overdue.
 *
 * `record` never read it. A tick on a closed day was measured against a
 * deadline the same screen had just said did not apply, marked late, and
 * given a penalty — so the board said «no deadline today» while the
 * record said «late» about the same morning.
 *
 * Since the tariff started writing its own deduction, that disagreement
 * is money off somebody's wages for being late to a shop that was shut.
 */
class AClosedDayHasNoDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'late_free_days' => 0,
            'late_tier1_last_day' => 3,
            'late_tier1_amount' => 100,
            'late_tier2_amount' => 200,
            'baking_start_deadline' => '06:00',
            'chane_start_deadline' => '05:40',
        ]);

        $this->baker = User::factory()->create(['is_active' => true]);
        $this->baker->assignRole('shater');
    }

    private function closeTheShop(Carbon $day): void
    {
        Holiday::create([
            'date' => $day->toDateString(),
            'title' => 'تعطیلی نانوایی',
            'type' => 'shop',
        ]);
    }

    public function test_an_open_day_still_charges_for_being_late(): void
    {
        // The guard must not turn the tariff off altogether.
        $late = now()->timezone(config('app.timezone'))->setTime(7, 30);

        $record = WorkStart::record(WorkStart::BAKING, $this->baker->id, $late);

        $this->assertTrue($record->is_late);
        $this->assertGreaterThan(0, (float) $record->penalty_amount);
    }

    public function test_a_closed_day_is_not_late(): void
    {
        $late = now()->timezone(config('app.timezone'))->setTime(7, 30);
        $this->closeTheShop($late);

        $record = WorkStart::record(WorkStart::BAKING, $this->baker->id, $late);

        $this->assertFalse($record->is_late, 'روز تعطیل نباید تأخیر حساب شود.');
        $this->assertEquals(0, (float) $record->penalty_amount);
        $this->assertSame(0, (int) $record->late_minutes);
    }

    public function test_a_closed_day_costs_nobody_anything(): void
    {
        // The whole point, now that the deduction writes itself.
        $late = now()->timezone(config('app.timezone'))->setTime(7, 30);
        $this->closeTheShop($late);

        WorkStart::record(WorkStart::BAKING, $this->baker->id, $late);

        $this->assertNull(
            StaffAdjustment::acrossBakeries()
                ->where('source', LateDeduction::SOURCE)
                ->first(),
            'برای روز تعطیل کسر حقوق نوشته شد.',
        );
    }

    public function test_the_work_is_still_recorded(): void
    {
        // Somebody came in and did the work. Not charging them is not the
        // same as pretending they were not there.
        $late = now()->timezone(config('app.timezone'))->setTime(7, 30);
        $this->closeTheShop($late);

        $record = WorkStart::record(WorkStart::BAKING, $this->baker->id, $late);

        $this->assertNotNull($record->id);
        $this->assertSame($this->baker->id, $record->user_id);
    }

    public function test_zero_free_days_means_zero_and_not_three(): void
    {
        // `?:` read a chosen zero as «not set» and handed back the default
        // three. A shop that set no tolerance got three free days a month
        // and nothing said so. The two tariff amounts beside it were
        // already written with `??`, so the difference was known and
        // applied to half the settings.
        Bakery::first()->update(['late_free_days' => 0]);

        $late = now()->timezone(config('app.timezone'))->setTime(7, 30);
        $record = WorkStart::record(WorkStart::BAKING, $this->baker->id, $late);

        $this->assertSame(1, $record->late_sequence);
        $this->assertEquals(100, (float) $record->penalty_amount);
    }

    public function test_a_first_tier_of_zero_charges_the_second_from_the_start(): void
    {
        // Same shape, same setting page: «تا روز چندم نرخ اول» set to zero
        // means there is no first tier at all.
        Bakery::first()->update([
            'late_free_days' => 0,
            'late_tier1_last_day' => 0,
        ]);

        $late = now()->timezone(config('app.timezone'))->setTime(7, 30);
        $record = WorkStart::record(WorkStart::BAKING, $this->baker->id, $late);

        $this->assertEquals(200, (float) $record->penalty_amount);
    }

    public function test_a_closed_day_does_not_use_up_a_free_day(): void
    {
        // The tariff escalates by the count of late days. A day that
        // cannot be late must not advance that count, or the next real
        // one is charged at the wrong tier.
        Bakery::first()->update(['late_free_days' => 1]);

        $closed = now()->timezone(config('app.timezone'))->setTime(7, 30);
        $this->closeTheShop($closed);
        WorkStart::record(WorkStart::BAKING, $this->baker->id, $closed);

        $open = $closed->copy()->addDay()->setTime(7, 30);
        $next = WorkStart::record(WorkStart::CHANE, $this->baker->id, $open);

        // First real late day, so it lands on the free one.
        $this->assertSame(1, $next->late_sequence);
        $this->assertEquals(0, (float) $next->penalty_amount);
    }
}
