<?php

namespace Tests\Feature;

use App\Models\FlourAllocation;
use App\Support\IssueScanner;
use App\Support\Jalali;
use App\Support\SystemIssue;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The blind spot the reader check named and then left alone.
 *
 * `readerDisagreesWithUs()` compares the card reader's loaf count against
 * the shop's, and stays silent on a period nobody has entered a figure
 * for — correctly, because an unchecked period is not an agreeing one.
 * But nothing else pointed at those periods either. The shop health
 * command counts them; the owner does not run the shop health command.
 * So the one check that could catch a shrinking quota was quiet for
 * exactly the periods it could not see.
 *
 * The shop had three of them when this was written.
 *
 * What it costs: next month's allocation is worked out from the reader's
 * number, not from what the shop wrote down. A period left unchecked is a
 * month whose quota is settled without anybody having looked, and the
 * shop finds out when the flour arrives short.
 */
class APeriodNobodyCheckedIsAskedAboutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen well into the month on purpose.
        //
        // The periods are cut from the Shamsi month containing «now», so
        // on the first days of one nothing has finished yet and these
        // tests had nothing to assert — they skipped. A guard that is
        // only armed on some days of the month is not a guard, and the
        // days it sleeps through are ordinary ones.
        Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00'));

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** The periods of this month that are already over. */
    private function finished(FlourAllocation $allocation)
    {
        return $allocation->periods->filter(
            fn ($p) => $p->ends_on->lt(now()->startOfDay())
        );
    }

    private function allocation(): FlourAllocation
    {
        [$monthStart] = Jalali::currentMonthRange();

        $allocation = FlourAllocation::create([
            'month_start' => $monthStart,
            'month_label' => Jalali::monthLabel($monthStart) ?? '',
            'total_bags' => 345,
        ]);

        $allocation->syncPeriods();

        return $allocation->fresh('periods');
    }

    /** @return Collection<int, SystemIssue> */
    private function unchecked()
    {
        return app(IssueScanner::class)->scan()
            ->filter(fn ($issue) => str_starts_with($issue->key, 'reader-unchecked-'));
    }

    public function test_a_finished_period_with_no_reader_figure_is_reported(): void
    {
        $allocation = $this->allocation();

        $this->assertGreaterThan(
            0,
            $this->finished($allocation)->count(),
            'تاریخِ ثابتِ این آزمون باید دستِ‌کم یک دورهٔ تمام‌شده داشته باشد.',
        );

        $this->assertCount(1, $this->unchecked());
    }

    public function test_a_period_still_running_is_not_nagged_about(): void
    {
        $allocation = $this->allocation();

        // Every finished period answered; only the live one is left.
        $this->finished($allocation)
            ->each(fn ($p) => $p->update(['system_bread_count' => 100]));

        $this->assertCount(
            0,
            $this->unchecked(),
            'دوره‌ای که هنوز تمام نشده رقم نهایی ندارد و نباید سراغش را بگیرد.',
        );
    }

    public function test_answering_every_finished_period_clears_it(): void
    {
        $allocation = $this->allocation();

        $allocation->periods->each(
            fn ($p) => $p->update(['system_bread_count' => 100])
        );

        $this->assertCount(0, $this->unchecked());
    }

    public function test_the_row_says_how_many_are_waiting(): void
    {
        $allocation = $this->allocation();

        $waiting = $this->finished($allocation)->count();

        // The magnitude is what sorts one blind spot above another, so it
        // has to be the count and not a flat 1.
        $this->assertSame(
            (float) $waiting,
            $this->unchecked()->first()->magnitude,
        );
    }
}
