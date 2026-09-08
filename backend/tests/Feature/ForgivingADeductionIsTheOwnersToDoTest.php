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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * «در پرداخت دستم باز باشه.»
 *
 * The tariff writes its own deduction and rewrites it whenever a day
 * changes. That is what keeps the figure honest — and it is also what
 * took the owner's discretion away: forgiving somebody meant deleting a
 * row that came straight back, so deleting was refused outright.
 *
 * A rule that cannot be waived is not a rule the shop is running.
 *
 * What is tested hardest is that a waiver is worth something: the money
 * stays with the person, the payslip does not pick it up, and the tariff
 * does not quietly put it back the next morning somebody is late.
 */
class ForgivingADeductionIsTheOwnersToDoTest extends TestCase
{
    use RefreshDatabase;

    private User $baker;

    private User $owner;

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

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');
    }

    private function day(int $offset = 3, float $penalty = 100): WorkStart
    {
        $date = Jalali::currentMonthRange()[0]->copy()->addDays($offset);

        return WorkStart::create([
            'type' => $offset % 2 === 0 ? WorkStart::CHANE : WorkStart::BAKING,
            'date' => $date->toDateString(),
            'started_at' => $date->copy()->setTime(7, 0),
            'user_id' => $this->baker->id,
            'is_late' => true,
            'late_minutes' => 60,
            'late_sequence' => 1,
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

    private function waive(): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/v1/staff-adjustments/'.$this->deduction()->id.'/waive');
    }

    public function test_the_owner_can_forgive_it(): void
    {
        $this->day();

        $this->waive()->assertOk();

        $this->assertTrue($this->deduction()->fresh()->isWaived());
    }

    public function test_the_amount_stays_on_the_row(): void
    {
        // Deleting would lose the figure as well as the decision, and the
        // figure is what makes the decision legible next month.
        $this->day();

        $this->waive();

        $this->assertEquals(100, (float) $this->deduction()->fresh()->amount);
    }

    public function test_the_decision_has_a_name_on_it(): void
    {
        // Forgiving somebody a fine is a decision, and a decision with
        // nobody's name on it is a rule again.
        $this->day();

        $this->waive();

        $this->assertSame($this->owner->id, $this->deduction()->fresh()->waived_by);
    }

    public function test_the_month_stops_counting_it(): void
    {
        $this->day();
        $this->waive();

        [$from, $until] = Jalali::currentMonthRange();
        $month = StaffAdjustment::monthFor($this->baker->id, $from, $until);

        $this->assertEquals(0, $month['penalty'], 'کسر بخشیده‌شده هنوز شمرده می‌شود.');
    }

    public function test_a_payslip_does_not_pick_it_up(): void
    {
        $this->day();
        $this->waive();

        $payslip = SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => Jalali::currentMonthRange()[0],
            'period_label' => 'این ماه',
            'base_amount' => 10_000_000,
            'bonus' => 0,
            'deduction' => 0,
        ]);
        $payslip->claimAdjustments();

        $this->assertNull($this->deduction()->fresh()->salary_payment_id);
    }

    public function test_the_tariff_does_not_put_it_back(): void
    {
        // The morning after. Another late day rewrites the month's figure
        // for everybody else — and must leave this one alone, or the
        // waiver was cosmetic.
        $this->day(3);
        $this->waive();

        $this->day(5);

        $row = $this->deduction()->fresh();

        $this->assertTrue($row->isWaived());
        $this->assertEquals(100, (float) $row->amount, 'تعرفه روی تصمیم نوشت.');
    }

    public function test_it_can_be_taken_back_and_recomputed(): void
    {
        // Restoring hands the row to the tariff again, so an amount that
        // moved while it was forgiven comes back right rather than stale.
        $this->day(3);
        $this->waive();
        $this->day(5);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/v1/staff-adjustments/'.$this->deduction()->id.'/restore')
            ->assertOk();

        $row = $this->deduction()->fresh();

        $this->assertFalse($row->isWaived());
        $this->assertEquals(200, (float) $row->amount, 'پس از بازگرداندن باید دوباره حساب شود.');
    }

    public function test_a_settled_month_cannot_be_forgiven_after_the_fact(): void
    {
        // The payslip is written and somebody was paid that net.
        $this->day();

        $payslip = SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => Jalali::currentMonthRange()[0],
            'period_label' => 'این ماه',
            'base_amount' => 10_000_000,
            'bonus' => 0,
            'deduction' => 0,
        ]);
        $this->deduction()->update(['salary_payment_id' => $payslip->id]);

        $this->waive()->assertStatus(409);
    }

    public function test_forgiving_twice_is_not_an_error(): void
    {
        $this->day();

        $this->waive()->assertOk();
        $this->waive()->assertOk();
    }

    public function test_deleting_it_still_points_at_forgiving(): void
    {
        // The old message said it could not be changed at all, which was
        // true and useless.
        $this->day();

        $response = $this->actingAs($this->owner, 'sanctum')
            ->deleteJson('/api/v1/staff-adjustments/'.$this->deduction()->id)
            ->assertStatus(409);

        $this->assertStringContainsString('بخشیدن', $response->json('message'));
    }

    public function test_a_row_somebody_typed_is_untouched_by_any_of_this(): void
    {
        $manual = StaffAdjustment::create([
            'user_id' => $this->baker->id,
            'kind' => StaffAdjustment::PENALTY,
            'basis' => StaffAdjustment::BY_AMOUNT,
            'amount' => 50,
            'occurred_on' => now()->toDateString(),
            'reason' => 'دستی',
        ]);

        $this->assertFalse($manual->isWaived());

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson('/api/v1/staff-adjustments/'.$manual->id)
            ->assertOk();
    }
}
