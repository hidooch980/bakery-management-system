<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\Holiday;
use App\Models\User;
use App\Models\WorkStart;
use App\Support\LatePenalty;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkStartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'chane_start_deadline' => '05:40',
            'baking_start_deadline' => '06:00',
            'currency' => 'toman',
            'late_free_days' => 3,
            'late_tier1_last_day' => 7,
            'late_tier1_amount' => 200000,
            'late_tier2_amount' => 500000,
        ]);
        Money::forgetCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(string $role = 'chane_gir'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** Freezes the clock at a Tehran wall-clock time today. */
    private function atTime(string $time): void
    {
        $now = Carbon::now(config('app.timezone'));
        [$h, $m] = array_map('intval', explode(':', $time));

        Carbon::setTestNow($now->copy()->setTime($h, $m, 0));
    }

    // ------------------------------------------------------- the deadlines

    public function test_deadlines_come_from_the_settings(): void
    {
        $this->assertEquals('05:40', WorkStart::deadlineFor(WorkStart::CHANE));
        $this->assertEquals('06:00', WorkStart::deadlineFor(WorkStart::BAKING));
    }

    public function test_deadlines_fall_back_to_the_defaults(): void
    {
        Bakery::first()->update([
            'chane_start_deadline' => null,
            'baking_start_deadline' => null,
        ]);

        $this->assertEquals('05:40', WorkStart::deadlineFor(WorkStart::CHANE));
        $this->assertEquals('06:00', WorkStart::deadlineFor(WorkStart::BAKING));
    }

    public function test_a_changed_deadline_is_honoured(): void
    {
        Bakery::first()->update(['chane_start_deadline' => '05:00']);

        $this->assertEquals('05:00', WorkStart::deadlineFor(WorkStart::CHANE));
    }

    // ------------------------------------------------- on time versus late

    public function test_starting_before_the_deadline_is_on_time(): void
    {
        $this->atTime('05:30');

        $record = WorkStart::record(WorkStart::CHANE, $this->staff()->id);

        $this->assertFalse($record->is_late);
        $this->assertEquals(0, $record->late_minutes);
        $this->assertNull($record->warning);
    }

    public function test_starting_exactly_on_the_deadline_is_on_time(): void
    {
        // 05:40 is the deadline, not the first late minute.
        $this->atTime('05:40');

        $this->assertFalse(
            WorkStart::record(WorkStart::CHANE, $this->staff()->id)->is_late
        );
    }

    public function test_starting_a_minute_late_is_late(): void
    {
        $this->atTime('05:41');

        $record = WorkStart::record(WorkStart::CHANE, $this->staff()->id);

        $this->assertTrue($record->is_late);
        $this->assertEquals(1, $record->late_minutes);
    }

    public function test_the_late_warning_names_the_delay(): void
    {
        $this->atTime('06:10');

        $record = WorkStart::record(WorkStart::CHANE, $this->staff()->id);

        $this->assertEquals(30, $record->late_minutes);
        $this->assertStringContainsString('30', $record->warning);
        // A first late day is a warning, so no deduction is mentioned yet.
        $this->assertStringContainsString('اخطار', $record->warning);
        $this->assertEquals(0.0, (float) $record->penalty_amount);
    }

    public function test_baking_is_judged_against_its_own_deadline(): void
    {
        // 05:50 is late for shaping but early for baking.
        $this->atTime('05:50');
        $user = $this->staff('shater')->id;

        $this->assertTrue(WorkStart::record(WorkStart::CHANE, $user)->is_late);
        $this->assertFalse(WorkStart::record(WorkStart::BAKING, $user)->is_late);
    }

    // -------------------------------------------------------- one per day

    public function test_a_second_tick_does_not_overwrite_the_first(): void
    {
        $user = $this->staff()->id;

        $this->atTime('05:30');
        WorkStart::record(WorkStart::CHANE, $user);

        // A double tap an hour later must not rewrite the real start time
        // and turn an on-time start into a late one.
        $this->atTime('06:30');
        $second = WorkStart::record(WorkStart::CHANE, $user);

        $this->assertFalse($second->is_late);
        $this->assertEquals('05:30', $second->started_at_time);
        $this->assertEquals(1, WorkStart::count());
    }

    public function test_lateness_is_frozen_against_a_later_settings_change(): void
    {
        $this->atTime('05:30');
        $record = WorkStart::record(WorkStart::CHANE, $this->staff()->id);

        // Moving the deadline earlier must not retroactively make a past
        // start late.
        Bakery::first()->update(['chane_start_deadline' => '05:00']);

        $this->assertFalse($record->fresh()->is_late);
    }

    // ---------------------------------------------------------- the board

    public function test_the_board_reports_time_left_before_the_deadline(): void
    {
        $this->atTime('05:20');

        $board = WorkStart::todayBoard();
        $chane = collect($board['items'])->firstWhere('type', WorkStart::CHANE);

        $this->assertFalse($chane['started']);
        $this->assertEquals(20, $chane['minutes_remaining']);
        $this->assertFalse($chane['overdue']);
    }

    public function test_the_board_flags_a_missed_deadline_with_no_tick(): void
    {
        $this->atTime('06:30');

        $board = WorkStart::todayBoard();
        $chane = collect($board['items'])->firstWhere('type', WorkStart::CHANE);

        $this->assertTrue($chane['overdue']);
    }

    public function test_a_closed_day_has_no_deadline_to_miss(): void
    {
        Holiday::create([
            'date' => now()->toDateString(),
            'title' => 'تعطیل',
            'type' => 'shop',
        ]);

        $this->atTime('09:00');

        $board = WorkStart::todayBoard();
        $chane = collect($board['items'])->firstWhere('type', WorkStart::CHANE);

        $this->assertTrue($board['is_holiday']);
        $this->assertFalse($chane['overdue']);
        $this->assertNull($chane['minutes_remaining']);
    }

    // -------------------------------------------------------- the endpoints

    public function test_chane_gir_ticks_the_start_of_shaping(): void
    {
        $this->atTime('05:30');

        $this->actingAs($this->staff('chane_gir'), 'sanctum')
            ->postJson('/api/v1/work-starts', ['type' => 'chane'])
            ->assertCreated()
            ->assertJsonPath('data.is_late', false)
            ->assertJsonPath('data.started_at', '05:30');
    }

    public function test_the_seller_can_tick_it_too(): void
    {
        $this->atTime('05:30');

        $this->actingAs($this->staff('seller'), 'sanctum')
            ->postJson('/api/v1/work-starts', ['type' => 'baking'])
            ->assertCreated();
    }

    public function test_a_late_tick_returns_the_deduction_warning(): void
    {
        $this->atTime('06:05');

        $this->actingAs($this->staff('chane_gir'), 'sanctum')
            ->postJson('/api/v1/work-starts', ['type' => 'chane'])
            ->assertCreated()
            ->assertJsonPath('data.is_late', true)
            ->assertJsonPath('data.late_minutes', 25);
    }

    public function test_ticking_twice_is_not_an_error(): void
    {
        $this->atTime('05:30');
        $user = $this->staff('chane_gir');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/work-starts', ['type' => 'chane'])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/work-starts', ['type' => 'chane'])
            ->assertOk()
            ->assertJsonPath('data.started_at', '05:30');
    }

    public function test_an_unknown_activity_is_rejected(): void
    {
        $this->actingAs($this->staff('chane_gir'), 'sanctum')
            ->postJson('/api/v1/work-starts', ['type' => 'cleaning'])
            ->assertStatus(422);
    }

    public function test_a_dough_maker_cannot_tick_the_start(): void
    {
        $this->actingAs($this->staff('dough_maker'), 'sanctum')
            ->postJson('/api/v1/work-starts', ['type' => 'chane'])
            ->assertForbidden();
    }

    public function test_only_the_seller_can_tick_baking_start(): void
    {
        $this->atTime('05:30');

        $this->actingAs($this->staff('shater'), 'sanctum')
            ->postJson('/api/v1/work-starts', ['type' => 'baking'])
            ->assertForbidden();

        $this->actingAs($this->staff('chane_gir'), 'sanctum')
            ->postJson('/api/v1/work-starts', ['type' => 'baking'])
            ->assertForbidden();

        $this->actingAs($this->staff('seller'), 'sanctum')
            ->postJson('/api/v1/work-starts', ['type' => 'baking'])
            ->assertCreated();
    }

    public function test_only_the_chane_gir_can_tick_shaping_start(): void
    {
        $this->atTime('05:30');

        // Holding record-work-start is not enough: the seller has it, but
        // shaping is not their activity to start.
        $this->actingAs($this->staff('seller'), 'sanctum')
            ->postJson('/api/v1/work-starts', ['type' => 'chane'])
            ->assertForbidden();

        $this->actingAs($this->staff('chane_gir'), 'sanctum')
            ->postJson('/api/v1/work-starts', ['type' => 'chane'])
            ->assertCreated();
    }

    public function test_an_admin_can_still_tick_either_activity(): void
    {
        $this->atTime('05:30');
        $admin = $this->staff('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/work-starts', ['type' => 'chane'])
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/work-starts', ['type' => 'baking'])
            ->assertCreated();
    }

    public function test_everyone_can_read_the_board(): void
    {
        $this->actingAs($this->staff('dough_maker'), 'sanctum')
            ->getJson('/api/v1/work-starts/today')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
    }

    // ------------------------------------------------------- the tariff

    public function test_the_first_three_late_days_are_warnings_only(): void
    {
        foreach ([1, 2, 3] as $sequence) {
            $this->assertEquals(0.0, LatePenalty::amountFor($sequence));
        }
    }

    public function test_days_four_to_seven_cost_the_lower_rate(): void
    {
        foreach ([4, 5, 6, 7] as $sequence) {
            // 200,000 Toman is the 2,000,000 Rial the tariff is quoted in.
            $this->assertEquals(200_000.0, LatePenalty::amountFor($sequence));
        }
    }

    public function test_from_the_eighth_day_the_rate_rises(): void
    {
        foreach ([8, 12, 30] as $sequence) {
            $this->assertEquals(500_000.0, LatePenalty::amountFor($sequence));
        }
    }

    public function test_the_running_total_follows_the_tariff(): void
    {
        // Three free, then four at 200,000.
        $this->assertEquals(800_000.0, LatePenalty::totalFor(7));

        // Plus two at 500,000.
        $this->assertEquals(1_800_000.0, LatePenalty::totalFor(9));
    }

    public function test_the_tariff_is_configurable(): void
    {
        Bakery::first()->update([
            'late_free_days' => 1,
            'late_tier1_amount' => 100000,
        ]);

        $this->assertEquals(0.0, LatePenalty::amountFor(1));
        $this->assertEquals(100_000.0, LatePenalty::amountFor(2));
    }

    // ------------------------------------------- the tariff in practice

    /** Records a late start on a given day of this Jalali month. */
    private function lateOn(int $dayOffset, string $type = WorkStart::CHANE): WorkStart
    {
        [$monthStart] = \App\Support\Jalali::currentMonthRange();
        $day = $monthStart->copy()->addDays($dayOffset)->setTime(7, 0, 0);

        Carbon::setTestNow($day);

        return WorkStart::record($type, $this->staff()->id);
    }

    public function test_the_first_late_day_carries_no_deduction(): void
    {
        $record = $this->lateOn(0);

        $this->assertTrue($record->is_late);
        $this->assertEquals(1, $record->late_sequence);
        $this->assertEquals(0.0, (float) $record->penalty_amount);
        $this->assertStringContainsString('اخطار', $record->warning);
    }

    public function test_the_fourth_late_day_is_charged(): void
    {
        foreach ([0, 1, 2] as $offset) {
            $this->lateOn($offset);
        }

        $fourth = $this->lateOn(3);

        $this->assertEquals(4, $fourth->late_sequence);
        $this->assertEquals(200_000.0, (float) $fourth->penalty_amount);
        $this->assertStringContainsString('کسر حقوق', $fourth->warning);
    }

    public function test_the_eighth_late_day_is_charged_at_the_higher_rate(): void
    {
        foreach (range(0, 6) as $offset) {
            $this->lateOn($offset);
        }

        $eighth = $this->lateOn(7);

        $this->assertEquals(8, $eighth->late_sequence);
        $this->assertEquals(500_000.0, (float) $eighth->penalty_amount);
    }

    public function test_two_late_activities_on_one_day_are_charged_once(): void
    {
        foreach ([0, 1, 2] as $offset) {
            $this->lateOn($offset);
        }

        // The fourth late day: shaping is charged, and baking on the same
        // day must not be charged again.
        $chane = $this->lateOn(3, WorkStart::CHANE);
        $baking = $this->lateOn(3, WorkStart::BAKING);

        $this->assertEquals(200_000.0, (float) $chane->penalty_amount);
        $this->assertEquals(0.0, (float) $baking->penalty_amount);
        // Both belong to the same late day.
        $this->assertEquals(4, $baking->late_sequence);
    }

    public function test_the_month_summary_totals_the_deductions(): void
    {
        foreach (range(0, 4) as $offset) {
            $this->lateOn($offset);
        }

        $summary = WorkStart::monthSummary();

        $this->assertEquals(5, $summary['late_days']);
        $this->assertEquals(0, $summary['warnings_remaining']);
        // Two charged days at 200,000.
        $this->assertEquals(400_000.0, $summary['penalty_total']);
    }

    public function test_the_summary_counts_down_the_free_warnings(): void
    {
        $this->lateOn(0);

        $this->assertEquals(2, WorkStart::monthSummary()['warnings_remaining']);
    }

    public function test_every_member_of_staff_can_read_the_tariff(): void
    {
        // Announced up front rather than discovered when money is deducted.
        $this->actingAs($this->staff('dough_maker'), 'sanctum')
            ->getJson('/api/v1/work-starts/rules')
            ->assertOk()
            ->assertJsonPath('data.free_days', 3)
            ->assertJsonPath('data.tier1_last_day', 7)
            ->assertJsonPath('data.tier1_amount', 200000)
            ->assertJsonPath('data.tier2_amount', 500000);
    }

    public function test_the_board_carries_the_tariff_for_everyone(): void
    {
        $this->actingAs($this->staff('seller'), 'sanctum')
            ->getJson('/api/v1/work-starts/today')
            ->assertOk()
            ->assertJsonPath('data.tariff.free_days', 3)
            ->assertJsonStructure(['data' => ['month_summary' => ['late_days']]]);
    }

    public function test_the_tariff_is_reported_in_the_display_unit(): void
    {
        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        // 200,000 Toman is the 2,000,000 Rial the rule was written in.
        $this->actingAs($this->staff('seller'), 'sanctum')
            ->getJson('/api/v1/work-starts/rules')
            ->assertOk()
            ->assertJsonPath('data.tier1_amount', 2000000)
            ->assertJsonPath('data.tier2_amount', 5000000);
    }

    // ---------------------------------------------------- the late report

    public function test_the_late_report_totals_the_delays(): void
    {
        $user = $this->staff('chane_gir');

        $this->atTime('06:00');
        WorkStart::record(WorkStart::CHANE, $user->id);

        $this->atTime('06:30');
        WorkStart::record(WorkStart::BAKING, $user->id);

        $admin = $this->staff('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/work-starts/late-report')
            ->assertOk()
            ->assertJsonPath('data.late_count', 2)
            // 20 minutes on shaping plus 30 on baking.
            ->assertJsonPath('data.late_minutes_total', 50)
            // Both on the same day: one late day, still inside the free three.
            ->assertJsonPath('data.late_days', 1)
            ->assertJsonPath('data.penalty_total', 0);
    }

    public function test_the_late_report_is_grouped_by_person(): void
    {
        $user = $this->staff('chane_gir');

        $this->atTime('06:00');
        WorkStart::record(WorkStart::CHANE, $user->id);

        $this->actingAs($this->staff('admin'), 'sanctum')
            ->getJson('/api/v1/work-starts/late-report')
            ->assertOk()
            ->assertJsonPath('data.by_user.0.user', $user->name)
            ->assertJsonPath('data.by_user.0.late_count', 1);
    }

    public function test_staff_cannot_read_the_late_report(): void
    {
        $this->actingAs($this->staff('chane_gir'), 'sanctum')
            ->getJson('/api/v1/work-starts/late-report')
            ->assertForbidden();
    }
}
