<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\StaffAdjustment;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «تشویقی و تنبیهی اضافه بشه» — the owner, 2026-08-18.
 *
 * The payslip has always had a bonus and a deduction box, both typed at
 * the moment of payment, which is the end of a long month. Nobody
 * remembers who came in late on the 12th. So each is recorded on the day,
 * with a reason, and the pay sheet opens on the month's total.
 *
 * The part these tests exist to protect is what the server does NOT do:
 * it never adds the adjustments during save. The figure stored is the one
 * that was on screen. Silently helpful arithmetic between the screen and
 * the record is exactly the bug this shop spent 2026-08-17 finding.
 */
class RewardAndPenaltyAreRecordedOnTheDayTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');

        // 90,000,000 Rial a month, so a day of it is exactly 3,000,000.
        $this->baker = User::factory()->create([
            'is_active' => true,
            'monthly_salary' => 9_000_000,
        ]);
        $this->baker->assignRole('dough_maker');
    }

    private function record(array $body): array
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson('/api/v1/staff-adjustments', array_merge([
            'user_id' => $this->baker->id,
            'reason' => 'دلیل آزمایشی',
        ], $body))->assertCreated()->json('data');
    }

    private function staffRow(): array
    {
        Sanctum::actingAs($this->owner);

        $list = $this->getJson('/api/v1/salaries/employees')->assertOk()->json('data');

        return collect($list)->firstWhere('id', $this->baker->id);
    }

    public function test_a_reward_in_rial_is_stored_in_toman(): void
    {
        $row = $this->record([
            'kind' => 'reward',
            'basis' => 'amount',
            'amount' => 5_000_000,
        ]);

        $this->assertEqualsWithDelta(5_000_000, $row['value'], 0.01);
        $this->assertEqualsWithDelta(500_000, (float) StaffAdjustment::first()->amount, 0.01);
    }

    public function test_half_a_day_is_priced_from_this_persons_own_wage(): void
    {
        $row = $this->record([
            'kind' => 'penalty',
            'basis' => 'days',
            'days' => 0.5,
            'reason' => 'نیم روز تأخیر',
        ]);

        // 90,000,000 ÷ 30 ÷ 2. The same half-day costs a different person
        // a different amount, which is the point of pricing it here.
        $this->assertEqualsWithDelta(1_500_000, $row['value'], 0.01);
        $this->assertSame('نیم روز', $row['basis_label']);
    }

    public function test_a_note_only_penalty_is_worth_nothing(): void
    {
        $row = $this->record([
            'kind' => 'penalty',
            'basis' => 'note',
            'reason' => 'تذکر شفاهی بابت دیر آمدن',
        ]);

        // On the record and costing nothing, which is a different thing
        // from an amount that happens to be zero.
        $this->assertSame(0.0, (float) $row['value']);
        $this->assertTrue($row['is_note_only']);
        $this->assertSame('—', $row['value_formatted']);
    }

    public function test_an_amount_basis_with_no_amount_is_refused(): void
    {
        Sanctum::actingAs($this->owner);

        // Otherwise it saves as worth nothing and is indistinguishable
        // from a note-only entry, and only one of them was meant.
        $this->postJson('/api/v1/staff-adjustments', [
            'user_id' => $this->baker->id,
            'kind' => 'reward',
            'basis' => 'amount',
            'reason' => 'بدون مبلغ',
        ])->assertStatus(422);
    }

    public function test_a_reason_is_required(): void
    {
        Sanctum::actingAs($this->owner);

        // A deduction nobody can explain a month later is one the person
        // it was taken from will dispute, and they will be right to.
        $this->postJson('/api/v1/staff-adjustments', [
            'user_id' => $this->baker->id,
            'kind' => 'penalty',
            'basis' => 'amount',
            'amount' => 1_000_000,
        ])->assertStatus(422);
    }

    public function test_days_need_a_wage_on_file(): void
    {
        $newcomer = User::factory()->create(['is_active' => true, 'monthly_salary' => null]);
        $newcomer->assignRole('dough_maker');

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/staff-adjustments', [
            'user_id' => $newcomer->id,
            'kind' => 'penalty',
            'basis' => 'days',
            'days' => 1,
            'reason' => 'غیبت',
        ])->assertStatus(422);
    }

    public function test_the_pay_sheet_opens_on_the_months_totals(): void
    {
        $this->record(['kind' => 'reward', 'basis' => 'amount', 'amount' => 5_000_000]);
        $this->record(['kind' => 'penalty', 'basis' => 'days', 'days' => 1, 'reason' => 'یک روز غیبت']);
        $this->record(['kind' => 'penalty', 'basis' => 'note', 'reason' => 'تذکر']);

        $row = $this->staffRow();

        $this->assertEqualsWithDelta(5_000_000, $row['suggested_bonus'], 0.01);
        $this->assertEqualsWithDelta(3_000_000, $row['suggested_deduction'], 0.01);
        $this->assertSame(3, $row['adjustment_count']);
    }

    public function test_rewards_and_penalties_are_kept_apart(): void
    {
        $this->record(['kind' => 'reward', 'basis' => 'amount', 'amount' => 4_000_000]);
        $this->record(['kind' => 'penalty', 'basis' => 'amount', 'amount' => 4_000_000]);

        $row = $this->staffRow();

        // Not netted to zero. Someone who earned a reward and took a
        // penalty in the same month is owed the sight of both.
        $this->assertEqualsWithDelta(4_000_000, $row['suggested_bonus'], 0.01);
        $this->assertEqualsWithDelta(4_000_000, $row['suggested_deduction'], 0.01);
    }

    public function test_the_server_does_not_add_them_during_save(): void
    {
        $this->record(['kind' => 'reward', 'basis' => 'amount', 'amount' => 5_000_000]);

        Sanctum::actingAs($this->owner);
        $stored = $this->postJson('/api/v1/salaries', [
            'user_id' => $this->baker->id,
            'period_start' => Jalali::date(Jalali::currentMonthRange()[0]),
            'base_amount' => 90_000_000,
            'bonus' => 0,
            'paid_on' => Jalali::date(now()),
        ])->assertCreated()->json('data');

        // Zero was sent and zero is stored. The suggestion is a
        // suggestion; what the owner confirmed is what the shop owes.
        $this->assertSame(0.0, (float) $stored['bonus']);
        $this->assertEqualsWithDelta(90_000_000, $stored['net_amount'], 0.01);
    }

    public function test_a_payslip_claims_the_month_so_it_is_not_offered_twice(): void
    {
        $this->record(['kind' => 'reward', 'basis' => 'amount', 'amount' => 5_000_000]);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/salaries', [
            'user_id' => $this->baker->id,
            'period_start' => Jalali::date(Jalali::currentMonthRange()[0]),
            'base_amount' => 90_000_000,
            'bonus' => 5_000_000,
            'paid_on' => Jalali::date(now()),
        ])->assertCreated();

        // Paid for once. Still on the record, but no longer suggested.
        $this->assertSame(0.0, (float) $this->staffRow()['suggested_bonus']);
        $this->assertNotNull(StaffAdjustment::first()->salary_payment_id);
    }

    public function test_deleting_the_payslip_hands_the_month_back(): void
    {
        $this->record(['kind' => 'reward', 'basis' => 'amount', 'amount' => 5_000_000]);

        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/salaries', [
            'user_id' => $this->baker->id,
            'period_start' => Jalali::date(Jalali::currentMonthRange()[0]),
            'base_amount' => 90_000_000,
            'bonus' => 5_000_000,
            'paid_on' => Jalali::date(now()),
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/salaries/{$id}")->assertOk();

        // A wage taken back has settled nothing, and the reward is owed
        // again.
        $this->assertEqualsWithDelta(5_000_000, $this->staffRow()['suggested_bonus'], 0.01);
        $this->assertNull(StaffAdjustment::first()->salary_payment_id);
    }

    public function test_something_already_paid_for_cannot_be_deleted(): void
    {
        $this->record(['kind' => 'penalty', 'basis' => 'amount', 'amount' => 2_000_000]);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/salaries', [
            'user_id' => $this->baker->id,
            'period_start' => Jalali::date(Jalali::currentMonthRange()[0]),
            'base_amount' => 90_000_000,
            'deduction' => 2_000_000,
            'paid_on' => Jalali::date(now()),
        ])->assertCreated();

        // Deleting it now would leave a payslip docked for a reason that
        // no longer exists anywhere.
        $this->deleteJson('/api/v1/staff-adjustments/'.StaffAdjustment::first()->id)
            ->assertStatus(409);
    }

    public function test_an_unpaid_one_can_be_deleted(): void
    {
        $this->record(['kind' => 'penalty', 'basis' => 'amount', 'amount' => 2_000_000]);

        Sanctum::actingAs($this->owner);
        $this->deleteJson('/api/v1/staff-adjustments/'.StaffAdjustment::first()->id)->assertOk();

        $this->assertSame(0, StaffAdjustment::count());
    }

    public function test_the_period_endpoint_lists_what_the_month_is_made_of(): void
    {
        $this->record(['kind' => 'reward', 'basis' => 'amount', 'amount' => 5_000_000, 'reason' => 'شیفت اضافه']);
        $this->record(['kind' => 'penalty', 'basis' => 'days', 'days' => 0.5, 'reason' => 'تأخیر']);

        Sanctum::actingAs($this->owner);
        $data = $this->getJson('/api/v1/staff-adjustments/period?user_id='.$this->baker->id)
            ->assertOk()->json('data');

        $this->assertCount(2, $data['items']);
        $this->assertEqualsWithDelta(5_000_000, $data['reward_total'], 0.01);
        $this->assertEqualsWithDelta(1_500_000, $data['penalty_total'], 0.01);

        // The reasons come back with them: a total nobody can break down
        // is a total that gets argued about.
        $this->assertSame('شیفت اضافه', $data['items'][0]['reason']);
    }

    public function test_last_months_entries_do_not_land_in_this_month(): void
    {
        $this->record(['kind' => 'reward', 'basis' => 'amount', 'amount' => 5_000_000]);

        StaffAdjustment::first()->update([
            'occurred_on' => Jalali::currentMonthRange()[0]->copy()->subDays(5),
        ]);

        $this->assertSame(0.0, (float) $this->staffRow()['suggested_bonus']);
    }

    public function test_a_payslip_only_claims_its_own_month(): void
    {
        $this->record(['kind' => 'reward', 'basis' => 'amount', 'amount' => 5_000_000]);

        [$thisMonth] = Jalali::currentMonthRange();
        $lastMonth = $thisMonth->copy()->subDay();

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/salaries', [
            'user_id' => $this->baker->id,
            'period_start' => Jalali::date(Jalali::monthRangeFor($lastMonth)[0]),
            'base_amount' => 90_000_000,
            'paid_on' => Jalali::date(now()),
        ])->assertCreated();

        // Last month's payslip must not swallow this month's reward.
        $this->assertNull(StaffAdjustment::first()->salary_payment_id);
        $this->assertEqualsWithDelta(5_000_000, $this->staffRow()['suggested_bonus'], 0.01);
    }

    public function test_a_days_penalty_reprices_if_the_wage_changes(): void
    {
        $this->record(['kind' => 'penalty', 'basis' => 'days', 'days' => 1, 'reason' => 'غیبت']);

        $this->assertEqualsWithDelta(3_000_000, $this->staffRow()['suggested_deduction'], 0.01);

        // Agreed a higher wage before payday. A day off that wage is worth
        // more, which is what "a day's pay" means.
        $this->baker->update(['monthly_salary' => 12_000_000]);

        $this->assertEqualsWithDelta(4_000_000, $this->staffRow()['suggested_deduction'], 0.01);
    }
}
