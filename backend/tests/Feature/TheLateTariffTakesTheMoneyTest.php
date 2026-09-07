<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\SalaryPayment;
use App\Models\StaffAdjustment;
use App\Models\User;
use App\Models\WorkStart;
use App\Support\Jalali;
use App\Support\LateDeduction;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * «همه خودکار بشه.»
 *
 * The tariff was computed, stored on each record and shown to the person
 * it was about — «جریمه تا اینجا» — while nothing deducted it. The payslip
 * reads `StaffAdjustment` rows and nothing created one from a late day, so
 * «کسر می‌شود» was true only if somebody remembered, every month, from
 * figures no screen displayed.
 *
 * This is money coming off wages without anybody typing it, so what is
 * tested hardest is the cases where it must NOT: a month already paid, a
 * day that stopped being late, and the same day counted twice.
 */
class TheLateTariffTakesTheMoneyTest extends TestCase
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

        $this->baker = User::factory()->create(['is_active' => true]);
        $this->baker->assignRole('shater');
    }

    private function lateDay(string $date, float $penalty, int $sequence = 1): WorkStart
    {
        return WorkStart::create([
            'type' => WorkStart::BAKING,
            'date' => $date,
            'started_at' => Carbon::parse($date.' 07:00'),
            'user_id' => $this->baker->id,
            'is_late' => true,
            'late_minutes' => 60,
            'late_sequence' => $sequence,
            'penalty_amount' => $penalty,
            'deadline' => '06:00',
        ]);
    }

    private function deduction(): ?StaffAdjustment
    {
        return StaffAdjustment::acrossBakeries()
            ->where('user_id', $this->baker->id)
            ->where('source', LateDeduction::SOURCE)
            ->first();
    }

    private function today(): string
    {
        [$from] = Jalali::currentMonthRange();

        return $from->copy()->addDays(3)->toDateString();
    }

    public function test_a_late_day_writes_the_deduction_by_itself(): void
    {
        $this->lateDay($this->today(), 100);

        $row = $this->deduction();

        $this->assertNotNull($row, 'کسر ثبت نشد.');
        $this->assertSame(StaffAdjustment::PENALTY, $row->kind);
        $this->assertEquals(100, (float) $row->amount);
    }

    public function test_nobody_is_named_as_having_decided_it(): void
    {
        // A tariff is a rule. `recorded_by` pointing at a person would say
        // somebody chose to dock this baker, which is the opposite of what
        // an automatic rule is for.
        $this->lateDay($this->today(), 100);

        $this->assertNull($this->deduction()->recorded_by);
    }

    public function test_a_second_late_day_adds_to_the_same_row(): void
    {
        // One row per person per month, rewritten. A row per late day
        // would have to be renumbered every time an earlier one changed,
        // because the tariff escalates — and a missed renumbering is
        // money.
        [$from] = Jalali::currentMonthRange();

        $this->lateDay($from->copy()->addDays(3)->toDateString(), 100, 1);
        $this->lateDay($from->copy()->addDays(5)->toDateString(), 100, 2);

        $this->assertSame(1, StaffAdjustment::acrossBakeries()
            ->where('source', LateDeduction::SOURCE)->count());
        $this->assertEquals(200, (float) $this->deduction()->amount);
    }

    public function test_removing_the_day_removes_the_money(): void
    {
        $day = $this->lateDay($this->today(), 100);

        $this->assertNotNull($this->deduction());

        $day->delete();

        // Not left sitting at zero: a «کسر: ۰» on a payslip reads as a
        // judgement about somebody.
        $this->assertNull($this->deduction());
    }

    public function test_a_day_corrected_to_on_time_stops_costing(): void
    {
        $day = $this->lateDay($this->today(), 100);

        $day->update(['is_late' => false, 'penalty_amount' => 0]);

        $this->assertNull($this->deduction());
    }

    public function test_a_month_already_on_a_payslip_is_left_alone(): void
    {
        // The figure is part of a net somebody was paid. Moving it now
        // changes a document after the fact and the person holding it has
        // no way to know.
        $this->lateDay($this->today(), 100);

        $row = $this->deduction();
        $payslip = SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => Jalali::currentMonthRange()[0],
            'period_label' => 'این ماه',
            'base_amount' => 10_000_000,
            'bonus' => 0,
            'deduction' => 0,
        ]);
        $row->update(['salary_payment_id' => $payslip->id]);

        [$from] = Jalali::currentMonthRange();
        $this->lateDay($from->copy()->addDays(7)->toDateString(), 100, 2);

        $this->assertEquals(100, (float) $this->deduction()->fresh()->amount);
    }

    public function test_last_months_lateness_does_not_touch_this_months_row(): void
    {
        [$from] = Jalali::currentMonthRange();

        $this->lateDay($this->today(), 100);
        $this->lateDay($from->copy()->subDays(5)->toDateString(), 100, 1);

        // Two months, two rows, and this month's is still its own figure.
        $this->assertSame(2, StaffAdjustment::acrossBakeries()
            ->where('source', LateDeduction::SOURCE)->count());
    }

    public function test_a_row_the_tariff_wrote_cannot_be_deleted_by_hand(): void
    {
        // It would come back the next time a day changed, so the delete
        // would look like it worked and then undo itself.
        $this->lateDay($this->today(), 100);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/staff-adjustments/'.$this->deduction()->id)
            ->assertStatus(409);

        $this->assertNotNull($this->deduction());
    }

    public function test_a_row_somebody_typed_is_still_deletable(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $manual = StaffAdjustment::create([
            'user_id' => $this->baker->id,
            'kind' => StaffAdjustment::PENALTY,
            'basis' => StaffAdjustment::BY_AMOUNT,
            'amount' => 50,
            'occurred_on' => $this->today(),
            'reason' => 'دستی',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/staff-adjustments/'.$manual->id)
            ->assertOk();
    }

    public function test_syncing_twice_changes_nothing(): void
    {
        $this->lateDay($this->today(), 100);

        LateDeduction::sync($this->baker->id, Carbon::parse($this->today()));
        LateDeduction::sync($this->baker->id, Carbon::parse($this->today()));

        $this->assertSame(1, StaffAdjustment::acrossBakeries()
            ->where('source', LateDeduction::SOURCE)->count());
        $this->assertEquals(100, (float) $this->deduction()->amount);
    }

    public function test_the_row_belongs_to_the_shop_the_days_were_worked_at(): void
    {
        // Written from a console command there is no current bakery, and a
        // row with a null shop appears on every shop's pay sheet.
        $this->lateDay($this->today(), 100);

        $this->assertNotNull($this->deduction()->bakery_id);
    }
}
