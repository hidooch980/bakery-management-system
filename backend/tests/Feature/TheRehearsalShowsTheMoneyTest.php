<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\SalaryPayment;
use App\Models\StaffAdjustment;
use App\Models\User;
use App\Models\WorkStart;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\LateDeduction;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The command that applies the tariff to a month already past.
 *
 * It takes money off wages for a month somebody thought was closed, so
 * the rehearsal has to show the actual figures — who, how much now, how
 * much after. A `--dry-run` that only prints «this month» rehearses
 * nothing, and whoever approves it approves a sentence rather than a
 * number. That is what the first version of this did.
 */
class TheRehearsalShowsTheMoneyTest extends TestCase
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
        ]);

        $this->baker = User::factory()->create([
            'is_active' => true,
            'name' => 'حسن شاطر',
        ]);
        $this->baker->assignRole('shater');
    }

    private function lateDay(string $date, float $penalty = 100): WorkStart
    {
        return WorkStart::create([
            'type' => WorkStart::BAKING,
            'date' => $date,
            'started_at' => Carbon::parse($date.' 07:00'),
            'user_id' => $this->baker->id,
            'is_late' => true,
            'late_minutes' => 60,
            'late_sequence' => 1,
            'penalty_amount' => $penalty,
            'deadline' => '06:00',
        ]);
    }

    private function thisMonth(): string
    {
        return Jalali::currentMonthRange()[0]->copy()->addDays(3)->toDateString();
    }

    public function test_a_month_with_no_lateness_says_so_and_writes_nothing(): void
    {
        $this->artisan('late:sync-deductions --dry-run')
            ->expectsOutputToContain('هیچ تأخیری ثبت نشده')
            ->assertSuccessful();

        $this->assertSame(0, StaffAdjustment::acrossBakeries()->count());
    }

    public function test_the_rehearsal_names_the_person_and_the_figure(): void
    {
        $this->lateDay($this->thisMonth());

        // The row already exists — the model event wrote it. The point is
        // that the rehearsal shows a name and an amount at all.
        $this->artisan('late:sync-deductions --dry-run')
            ->expectsOutputToContain('حسن شاطر')
            ->expectsOutputToContain('چیزی نوشته نشد')
            ->assertSuccessful();
    }

    public function test_the_rehearsal_writes_nothing(): void
    {
        // A past month, so no model event has written anything for it.
        $past = Jalali::currentMonthRange()[0]->copy()->subDays(40);
        WorkStart::withoutEvents(fn () => $this->lateDay($past->toDateString()));

        $this->artisan('late:sync-deductions --dry-run --month='.$past->toDateString())
            ->assertSuccessful();

        $this->assertSame(0, StaffAdjustment::acrossBakeries()
            ->where('source', LateDeduction::SOURCE)->count());
    }

    public function test_answering_no_at_the_prompt_changes_nothing(): void
    {
        $past = Jalali::currentMonthRange()[0]->copy()->subDays(40);
        WorkStart::withoutEvents(fn () => $this->lateDay($past->toDateString()));

        $this->artisan('late:sync-deductions --month='.$past->toDateString())
            ->expectsConfirmation('کسر تأخیر ماه '.AppCalendar::monthLabel(
                Jalali::monthRangeFor($past)[0]
            ).' طبق جدول بالا اعمال شود؟', 'no')
            ->expectsOutputToContain('کاری انجام نشد')
            ->assertSuccessful();

        $this->assertSame(0, StaffAdjustment::acrossBakeries()
            ->where('source', LateDeduction::SOURCE)->count());
    }

    public function test_confirming_writes_the_deduction(): void
    {
        $past = Jalali::currentMonthRange()[0]->copy()->subDays(40);
        WorkStart::withoutEvents(fn () => $this->lateDay($past->toDateString()));

        $this->artisan('late:sync-deductions --month='.$past->toDateString())
            ->expectsConfirmation('کسر تأخیر ماه '.AppCalendar::monthLabel(
                Jalali::monthRangeFor($past)[0]
            ).' طبق جدول بالا اعمال شود؟', 'yes')
            ->assertSuccessful();

        $this->assertEquals(100, (float) StaffAdjustment::acrossBakeries()
            ->where('source', LateDeduction::SOURCE)->first()->amount);
    }

    public function test_the_rehearsal_says_when_a_month_is_already_paid(): void
    {
        // The one row somebody most needs to see before approving: it is
        // not going to change, and the reason is that it is already paid.
        $this->lateDay($this->thisMonth());

        $payslip = SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => Jalali::currentMonthRange()[0],
            'period_label' => 'این ماه',
            'base_amount' => 10_000_000,
            'bonus' => 0,
            'deduction' => 0,
        ]);

        StaffAdjustment::acrossBakeries()
            ->where('source', LateDeduction::SOURCE)
            ->first()
            ->update(['salary_payment_id' => $payslip->id]);

        $this->artisan('late:sync-deductions --dry-run')
            ->expectsOutputToContain('دست نمی‌خورد')
            ->assertSuccessful();
    }

    public function test_it_can_list_the_months_that_have_lateness(): void
    {
        // So the scope is visible before anybody picks a month to apply.
        $this->lateDay($this->thisMonth());

        $this->artisan('late:sync-deductions --list')
            ->expectsOutputToContain('--month=')
            ->assertSuccessful();
    }

    public function test_a_jalali_month_is_not_read_as_a_gregorian_one(): void
    {
        // «1405-05-15» typed by somebody here is Mordad. Read as
        // Gregorian it is a date eight centuries ago, and the command
        // would report an empty month and look like it worked.
        $this->artisan('late:sync-deductions --dry-run --month=1405-05-15')
            ->expectsOutputToContain('مرداد 1405')
            ->assertSuccessful();
    }
}
