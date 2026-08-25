<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\User;
use App\Models\WorkStart;
use App\Support\Jalali;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A tariff nobody can check is a fine, not a rule.
 *
 * Lateness is recorded, priced on an escalating scale and reported — to
 * the manager. `lateReport` sits behind view-work-start-report, so the
 * person it is about had no way to see it. They found out how many late
 * days they had when somebody told them, or when it came off their wages.
 *
 * That is the wrong order for an escalating tariff. The whole point of
 * one is that the next step can be seen coming while there is still time
 * to avoid it. This is that, on the person's own screen.
 */
class APersonCanSeeTheirOwnRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $baker;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        $this->baker = User::factory()->create(['is_active' => true]);
        $this->baker->assignRole('shater');

        $this->other = User::factory()->create(['is_active' => true]);
        $this->other->assignRole('dough_maker');
    }

    private function lateOn(User $user, string $date, int $minutes, string $type = 'baking'): WorkStart
    {
        return WorkStart::create([
            'user_id' => $user->id,
            'type' => $type,
            'date' => $date,
            'started_at' => now()->parse($date)->setTime(6, 0)->addMinutes($minutes),
            'deadline' => '06:00:00',
            'is_late' => $minutes > 0,
            'late_minutes' => $minutes,
        ]);
    }

    /**
     * A day inside the *Jalali* month, which is the window the endpoint
     * reads. `now()->startOfMonth()` is the Gregorian one and they rarely
     * overlap — on the day this was written the Jalali month began on 23
     * August, so records dated from 1 August fell outside it entirely and
     * every count came back zero.
     */
    private function dayOfMonth(int $offset): string
    {
        return Jalali::currentMonthRange()[0]->copy()->addDays($offset)->toDateString();
    }

    private function mine(?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->baker, 'sanctum')
            ->getJson('/api/v1/work-starts/mine');
    }

    public function test_a_baker_can_read_their_own_record_without_a_managers_permission(): void
    {
        // The whole point. `lateReport` answers this for the manager and
        // is gated; this one is about the person asking.
        $this->mine()->assertOk();
    }

    public function test_it_counts_late_days_and_not_late_ticks(): void
    {
        $day = $this->dayOfMonth(2);

        // Shaping and baking both ran late on the same day. That is one
        // late day and is charged once — the same count the tariff uses,
        // so this figure and the payslip cannot disagree.
        $this->lateOn($this->baker, $day, 20, 'shaping');
        $this->lateOn($this->baker, $day, 15, 'baking');

        $this->assertSame(1, $this->mine()->json('data.late_days'));
        $this->assertSame(35, $this->mine()->json('data.late_minutes_total'));
    }

    public function test_it_says_how_many_free_days_are_left(): void
    {
        $this->lateOn($this->baker, $this->dayOfMonth(1), 10);

        // Three free days by default, one used. The number that matters
        // to somebody reading this is the one still in hand.
        $this->assertSame(2, $this->mine()->json('data.free_days_left'));
    }

    public function test_it_says_what_the_next_late_day_would_cost(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->lateOn($this->baker, $this->dayOfMonth($i), 10);
        }

        $data = $this->mine()->json('data');

        // Three used, so the free run is over and the fourth costs money.
        // This is the only figure on the screen a person can still act on.
        $this->assertSame(0, $data['free_days_left']);
        $this->assertNotSame(
            Money::format(0),
            $data['next_late_day_costs_formatted'],
        );
    }

    public function test_somebody_with_a_clean_month_is_told_so_plainly(): void
    {
        $data = $this->mine()->json('data');

        $this->assertSame(0, $data['late_days']);
        $this->assertSame(Money::format(0), $data['penalty_formatted']);
        $this->assertSame(3, $data['free_days_left']);
    }

    public function test_one_persons_record_never_carries_another_persons_days(): void
    {
        $this->lateOn($this->other, $this->dayOfMonth(1), 40);
        $this->lateOn($this->other, $this->dayOfMonth(2), 40);

        // Somebody else's lateness on somebody else's screen would be
        // worse than not showing it at all.
        $this->assertSame(0, $this->mine()->json('data.late_days'));
        $this->assertSame(2, $this->mine($this->other)->json('data.late_days'));
    }

    public function test_the_recent_list_shows_the_times_and_the_deadline(): void
    {
        $this->lateOn($this->baker, $this->dayOfMonth(1), 25);

        $line = $this->mine()->json('data.recent.0');

        // «You were late» is an accusation. «You started at 06:25, the
        // deadline was 06:00» is a fact somebody can check against their
        // own morning.
        $this->assertTrue($line['is_late']);
        $this->assertSame(25, $line['late_minutes']);
        $this->assertSame('06:00:00', $line['deadline']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $line['started_at']);
    }

    public function test_the_tariff_itself_is_included(): void
    {
        // Without it the figures are numbers somebody else decided. With
        // it, a person can work out their own next step.
        $this->assertNotNull($this->mine()->json('data.tariff'));
    }
}
