<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\User;
use App\Support\Jalali;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_admin_records_a_holiday_by_jalali_date(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson('/api/v1/holidays', [
                'date' => '1405/05/10',
                'title' => 'عید سعید قربان',
                'type' => 'religious',
            ])
            ->assertCreated()
            ->assertJsonPath('data.date_display', '1405/05/10')
            ->assertJsonPath('data.type_label', 'مناسبت مذهبی');
    }

    public function test_a_day_can_only_be_a_holiday_once(): void
    {
        $admin = $this->userWithRole('admin');
        $payload = ['date' => '1405/05/10', 'title' => 'تعطیل'];

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/holidays', $payload)->assertCreated();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/holidays', $payload)->assertStatus(409);
    }

    public function test_holiday_rejects_an_invalid_date(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson('/api/v1/holidays', ['date' => 'دیروز', 'title' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');
    }

    public function test_staff_can_read_holidays_but_not_change_them(): void
    {
        Holiday::create(['date' => now(), 'title' => 'تعطیل', 'type' => 'official']);

        $seller = $this->userWithRole('seller');

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/holidays')->assertOk();

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/holidays', ['date' => '1405/06/01', 'title' => 'x'])
            ->assertForbidden();
    }

    public function test_today_endpoint_reports_whether_the_shop_is_closed(): void
    {
        $user = $this->userWithRole('seller');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/holidays/today')
            ->assertOk()
            ->assertJsonPath('data.is_holiday', false);

        Holiday::create(['date' => now(), 'title' => 'تعطیل رسمی', 'type' => 'official']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/holidays/today')
            ->assertOk()
            ->assertJsonPath('data.is_holiday', true)
            ->assertJsonPath('data.holiday.title', 'تعطیل رسمی');
    }

    public function test_holiday_lookup_by_date(): void
    {
        Holiday::create(['date' => '2026-07-25', 'title' => 'تعطیل', 'type' => 'official']);

        $this->assertTrue(Holiday::isHoliday('2026-07-25'));
        $this->assertFalse(Holiday::isHoliday('2026-07-26'));
    }

    public function test_month_scope_finds_holidays_in_the_jalali_month(): void
    {
        // 1405/05/10 is inside Mordad; 1405/06/02 is the following month.
        Holiday::create(['date' => Jalali::parse('1405/05/10'), 'title' => 'الف', 'type' => 'official']);
        Holiday::create(['date' => Jalali::parse('1405/06/02'), 'title' => 'ب', 'type' => 'official']);

        $inMordad = Holiday::query()
            ->inJalaliMonth(Jalali::parse('1405/05/20'))
            ->pluck('title')
            ->all();

        $this->assertSame(['الف'], $inMordad);
    }

    // ------------------------------------------------ monthly shop closures

    public function test_shop_closure_repeats_into_the_coming_months(): void
    {
        $rule = Holiday::create([
            'date' => Jalali::parse('1405/05/10'),
            'title' => 'تعطیلی هفتگی نانوایی',
            'type' => 'shop',
            'repeats_monthly' => true,
        ]);

        $created = $rule->generateFutureOccurrences(3);

        $this->assertSame(3, $created);

        $dates = Holiday::where('repeats_from_id', $rule->id)
            ->orderBy('date')
            ->get()
            ->map(fn (Holiday $h) => Jalali::date($h->date))
            ->all();

        $this->assertSame(['1405/06/10', '1405/07/10', '1405/08/10'], $dates);
    }

    public function test_only_a_shop_closure_may_repeat(): void
    {
        foreach (['official', 'religious'] as $type) {
            $holiday = Holiday::create([
                'date' => Jalali::parse('1405/05/1'.($type === 'official' ? '1' : '2')),
                'title' => 'تعطیل',
                'type' => $type,
                'repeats_monthly' => true,
            ]);

            // Official and religious dates move each year, so repeating them
            // monthly would invent holidays that do not exist.
            $this->assertSame(0, $holiday->generateFutureOccurrences(3));
        }
    }

    public function test_repeating_skips_months_without_that_day(): void
    {
        // The 31st exists only in the first six Jalali months.
        $rule = Holiday::create([
            'date' => Jalali::parse('1405/05/31'),
            'title' => 'تعطیلی نانوایی',
            'type' => 'shop',
            'repeats_monthly' => true,
        ]);

        $rule->generateFutureOccurrences(6);

        $dates = Holiday::where('repeats_from_id', $rule->id)
            ->orderBy('date')
            ->get()
            ->map(fn (Holiday $h) => Jalali::date($h->date))
            ->all();

        // Only Shahrivar has a 31st in the six months that follow Mordad.
        $this->assertSame(['1405/06/31'], $dates);
    }

    public function test_repeating_never_overwrites_an_existing_day(): void
    {
        Holiday::create([
            'date' => Jalali::parse('1405/06/10'),
            'title' => 'عید',
            'type' => 'official',
        ]);

        $rule = Holiday::create([
            'date' => Jalali::parse('1405/05/10'),
            'title' => 'تعطیلی نانوایی',
            'type' => 'shop',
            'repeats_monthly' => true,
        ]);

        $rule->generateFutureOccurrences(2);

        // Shahrivar 10 was already taken, so only Mehr was generated.
        $this->assertSame(1, Holiday::where('repeats_from_id', $rule->id)->count());
        $this->assertSame(
            'عید',
            Holiday::whereDate('date', Jalali::parse('1405/06/10'))->first()->title
        );
    }

    public function test_generating_twice_does_not_duplicate(): void
    {
        $rule = Holiday::create([
            'date' => Jalali::parse('1405/05/10'),
            'title' => 'تعطیلی نانوایی',
            'type' => 'shop',
            'repeats_monthly' => true,
        ]);

        $rule->generateFutureOccurrences(3);
        $rule->generateFutureOccurrences(3);

        $this->assertSame(3, Holiday::where('repeats_from_id', $rule->id)->count());
    }

    public function test_generated_occurrences_do_not_themselves_repeat(): void
    {
        $rule = Holiday::create([
            'date' => Jalali::parse('1405/05/10'),
            'title' => 'تعطیلی نانوایی',
            'type' => 'shop',
            'repeats_monthly' => true,
        ]);

        $rule->generateFutureOccurrences(2);

        $occurrence = Holiday::where('repeats_from_id', $rule->id)->first();

        $this->assertFalse($occurrence->is_rule);
        $this->assertSame(0, $occurrence->generateFutureOccurrences(3));
    }

    public function test_deleting_the_rule_removes_its_occurrences(): void
    {
        $rule = Holiday::create([
            'date' => Jalali::parse('1405/05/10'),
            'title' => 'تعطیلی نانوایی',
            'type' => 'shop',
            'repeats_monthly' => true,
        ]);

        $rule->generateFutureOccurrences(3);
        $this->assertSame(3, Holiday::where('repeats_from_id', $rule->id)->count());

        $rule->delete();

        // Occurrences must not outlive the rule that created them.
        $this->assertSame(0, Holiday::whereNotNull('repeats_from_id')->count());
    }

    public function test_attendance_summary_excludes_holidays_from_working_days(): void
    {
        $admin = $this->userWithRole('admin');
        $staff = $this->userWithRole('seller');

        $from = now()->subDays(4)->toDateString();
        $to = now()->toDateString();

        // Two of the five days are closed.
        Holiday::create(['date' => now()->subDays(1), 'title' => 'تعطیل ۱', 'type' => 'official']);
        Holiday::create(['date' => now()->subDays(2), 'title' => 'تعطیل ۲', 'type' => 'official']);

        Attendance::create([
            'user_id' => $staff->id,
            'date' => now()->subDays(3),
            'checked_in_at' => now()->subDays(3),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reports/attendance-summary?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('data.total_days', 5)
            ->assertJsonPath('data.holiday_count', 2)
            // Closed days are not counted against the staff.
            ->assertJsonPath('data.working_days', 3);
    }
}
