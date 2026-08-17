<?php

namespace Tests\Feature;

use App\Support\Jalali;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The shop's month runs from the 5th to the 4th, not from the 1st.
 *
 * The flour quota runs on that cycle and so does everything downstream of
 * it: how much may be drawn, which of the three delivery periods a bake
 * falls in, whether a period went over. Judging the shop by the Jalali
 * calendar month puts four days at each end into the wrong quota — a
 * mistake already made once on this system, which is why the boundary is
 * pinned here rather than trusted.
 *
 * The two ends are what matter. The 4th belongs to the period that opened
 * *last* month; the 1st of the next month still belongs to the one that
 * opened this month.
 */
class TheShopsOwnMonthTest extends TestCase
{
    /** @return array{0: string, 1: string} the period as Jalali dates */
    private function periodFor(string $gregorian): array
    {
        [$from, $to] = Jalali::quotaPeriodFor(Carbon::parse($gregorian));

        return [Jalali::date($from), Jalali::date($to)];
    }

    public function test_the_fifth_opens_a_period(): void
    {
        // 1405/05/05
        $this->assertSame(
            ['1405/05/05', '1405/06/04'],
            $this->periodFor('2026-07-27'),
        );
    }

    public function test_the_middle_of_the_month_is_in_that_period(): void
    {
        // 1405/05/26
        $this->assertSame(
            ['1405/05/05', '1405/06/04'],
            $this->periodFor('2026-08-17'),
        );
    }

    public function test_the_fourth_still_belongs_to_last_months_period(): void
    {
        // 1405/05/04 — the trap. It reads as this month by the calendar
        // and belongs to the period that opened on 1405/04/05.
        $this->assertSame(
            ['1405/04/05', '1405/05/04'],
            $this->periodFor('2026-07-26'),
        );
    }

    public function test_the_first_of_a_month_is_still_the_previous_period(): void
    {
        // 1405/05/01
        $this->assertSame(
            ['1405/04/05', '1405/05/04'],
            $this->periodFor('2026-07-23'),
        );
    }

    public function test_the_last_day_of_a_month_has_not_closed_the_period(): void
    {
        // 1405/05/31
        $this->assertSame(
            ['1405/05/05', '1405/06/04'],
            $this->periodFor('2026-08-22'),
        );
    }

    public function test_the_first_of_the_next_month_is_still_this_period(): void
    {
        // 1405/06/01 — four days before the next period opens.
        $this->assertSame(
            ['1405/05/05', '1405/06/04'],
            $this->periodFor('2026-08-23'),
        );
    }

    public function test_a_period_is_one_month_long_wherever_it_starts(): void
    {
        foreach (['2026-07-23', '2026-07-27', '2026-08-17', '2026-08-23'] as $day) {
            [$from, $to] = Jalali::quotaPeriodFor(Carbon::parse($day));

            // Jalali months are 29 to 31 days, so the span varies — what
            // must hold is that it ends the day before the next one opens.
            $next = Jalali::quotaPeriodFor($to->copy()->addDay());

            $this->assertTrue(
                $next[0]->isSameDay($to->copy()->addDay()),
                "the period after {$day} does not start the day this one ends",
            );
        }
    }

    public function test_the_label_says_it_the_way_the_shop_does(): void
    {
        $this->assertSame(
            '1405/05/05 تا 1405/06/04',
            Jalali::quotaPeriodLabel(Carbon::parse('2026-08-17')),
        );
    }
}
