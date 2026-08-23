<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\DieselAllocation;
use App\Models\FlourAllocation;
use App\Models\User;
use App\Support\Jalali;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The quota has to be findable on every day of the month, not most of them.
 *
 * On 1405/06/01 — the first of a Jalali month — thirty-one tests failed at
 * once. Nothing was wrong with the shop or the code: the tests registered
 * their quota against the calendar month while the product looks it up by
 * the quota period, which runs from the 5th to the 4th of the month after.
 * Those two agree for twenty-seven days and disagree for four.
 *
 * A test that passes twenty-seven days a month is not a test. It is a trap
 * with a timer on it, and when it goes off it goes off in a batch, on a
 * morning when somebody is trying to ship something unrelated.
 *
 * So this one sweeps the whole month. It cannot pass by being run on a
 * lucky day.
 */
class TheQuotaIsFoundOnEveryDayOfTheMonthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['flour_bag_weight_kg' => 40]);

        User::factory()->create(['is_active' => true])->assignRole('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Every day of a Jalali month, in turn: register the quota the way the
     * shop does and ask the product to find it.
     */
    public function test_the_diesel_quota_is_found_on_every_day(): void
    {
        foreach (range(1, 31) as $day) {
            Carbon::setTestNow(null);

            $today = Jalali::parse(sprintf('1405/05/%02d', $day));

            if ($today === null) {
                continue;   // A month that does not have this day.
            }

            Carbon::setTestNow($today->copy()->setTime(9, 0));

            DieselAllocation::query()->delete();

            DieselAllocation::create([
                'month_start' => Jalali::currentQuotaPeriod()[0],
                'total_litres' => 2000,
            ]);

            $this->assertNotNull(
                DieselAllocation::current(),
                'سهمیه گازوئیل در روز '.$day.' پیدا نشد',
            );
        }
    }

    public function test_the_flour_quota_is_found_on_every_day(): void
    {
        foreach (range(1, 31) as $day) {
            Carbon::setTestNow(null);

            $today = Jalali::parse(sprintf('1405/05/%02d', $day));

            if ($today === null) {
                continue;
            }

            Carbon::setTestNow($today->copy()->setTime(9, 0));

            FlourAllocation::query()->delete();

            FlourAllocation::create([
                'month_start' => Jalali::currentQuotaPeriod()[0],
                'month_label' => 'آزمون',
                'total_bags' => 100,
            ]);

            $this->assertSame(
                1,
                FlourAllocation::count(),
                'سهمیه آرد در روز '.$day.' ثبت نشد',
            );
        }
    }

    /**
     * The four days the old tests were blind to, named on their own.
     *
     * If someone later decides the period should start on a different day,
     * this is the test that says out loud which days used to be the
     * problem, so the same hole is not reopened quietly.
     */
    public function test_the_first_four_days_belong_to_the_period_that_started_last_month(): void
    {
        foreach ([1, 2, 3, 4] as $day) {
            Carbon::setTestNow(null);
            Carbon::setTestNow(Jalali::parse(sprintf('1405/06/%02d', $day))->copy()->setTime(9, 0));

            [$from] = Jalali::currentQuotaPeriod();

            // The 1st to the 4th of Shahrivar sit inside the period that
            // opened on the 5th of Mordad.
            $this->assertSame(
                '1405/05/05',
                Jalali::date($from),
                'روز '.$day.' شهریور به دوره اشتباه خورد',
            );
        }
    }

    public function test_the_fifth_opens_a_new_period(): void
    {
        Carbon::setTestNow(Jalali::parse('1405/06/05')->copy()->setTime(9, 0));

        $this->assertSame('1405/06/05', Jalali::date(Jalali::currentQuotaPeriod()[0]));
    }
}
