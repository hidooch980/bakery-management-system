<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\SalaryPaymentRequest;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «درخواست پرداخت حقوق از طرف کارکنان» — the owner, 2026-08-18.
 *
 * This shop traded for three weeks without writing a single payslip, and
 * the people owed the money had no way to say so except in person. So they
 * can ask, in writing, with a date on it.
 *
 * The design decision these tests exist to hold: **there is no approve
 * button.** Paying the person through the pay sheet is what approval
 * means. An approve endpoint would write a wage nobody had looked at, and
 * every figure on a payslip — the advance recovered, the month's rewards,
 * the account it leaves — has to be seen before the money moves.
 */
class StaffCanAskForTheirWageTest extends TestCase
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

        $this->baker = User::factory()->create([
            'is_active' => true,
            'monthly_salary' => 9_000_000,
        ]);
        $this->baker->assignRole('dough_maker');
    }

    private function ask(array $body = []): array
    {
        Sanctum::actingAs($this->baker);

        return $this->postJson('/api/v1/salary-requests', $body)
            ->assertCreated()->json('data');
    }

    private function payHim(): array
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson('/api/v1/salaries', [
            'user_id' => $this->baker->id,
            'period_start' => Jalali::date(Jalali::currentMonthRange()[0]),
            'base_amount' => 90_000_000,
            'paid_on' => Jalali::date(now()),
        ])->assertCreated()->json('data');
    }

    private function staffRow(): array
    {
        Sanctum::actingAs($this->owner);

        $list = $this->getJson('/api/v1/salaries/employees')->assertOk()->json('data');

        return collect($list)->firstWhere('id', $this->baker->id);
    }

    public function test_a_worker_can_ask_to_be_paid(): void
    {
        $row = $this->ask(['note' => 'حقوق مرداد را نگرفته‌ام']);

        $this->assertSame('pending', $row['status']);
        $this->assertSame('حقوق مرداد را نگرفته‌ام', $row['note']);
    }

    public function test_no_amount_is_asked_for(): void
    {
        $row = $this->ask();

        // The wage is what was agreed, less what has been drawn. Inviting a
        // figure would start a negotiation over a number the system knows,
        // and set him up to be told he was wrong.
        $this->assertArrayNotHasKey('amount', $row);
        $this->assertEqualsWithDelta(90_000_000, $row['estimated_net'], 0.01);
    }

    public function test_the_estimate_is_after_what_he_has_drawn(): void
    {
        StaffAdvance::create([
            'user_id' => $this->baker->id,
            'recorded_by' => $this->owner->id,
            'amount' => Money::toToman(20_000_000),
            'paid_on' => now(),
        ]);

        $this->assertEqualsWithDelta(70_000_000, $this->ask()['estimated_net'], 0.01);
    }

    public function test_someone_with_no_wage_on_file_is_told_so(): void
    {
        $newcomer = User::factory()->create(['is_active' => true, 'monthly_salary' => null]);
        $newcomer->assignRole('dough_maker');

        Sanctum::actingAs($newcomer);
        $this->postJson('/api/v1/salary-requests')->assertStatus(422);
    }

    public function test_asking_twice_for_one_month_is_refused(): void
    {
        $this->ask();

        Sanctum::actingAs($this->baker);
        $this->postJson('/api/v1/salary-requests')->assertStatus(409);
    }

    public function test_asking_for_a_month_already_paid_is_refused(): void
    {
        $this->payHim();

        Sanctum::actingAs($this->baker);

        // Said plainly rather than accepting a request that was answered
        // before it was made and would sit unanswered for ever.
        $this->postJson('/api/v1/salary-requests')->assertStatus(409);
    }

    public function test_he_can_take_it_back(): void
    {
        $id = $this->ask()['id'];

        Sanctum::actingAs($this->baker);
        $this->deleteJson("/api/v1/salary-requests/{$id}")->assertOk();

        $this->assertSame(0, SalaryPaymentRequest::count());
    }

    public function test_he_cannot_take_back_somebody_elses(): void
    {
        $other = User::factory()->create(['is_active' => true, 'monthly_salary' => 9_000_000]);
        $other->assignRole('dough_maker');

        $id = $this->ask()['id'];

        Sanctum::actingAs($other);
        $this->deleteJson("/api/v1/salary-requests/{$id}")->assertStatus(403);
    }

    public function test_the_payroll_list_shows_who_has_asked(): void
    {
        $this->ask();

        $row = $this->staffRow();

        $this->assertTrue($row['has_requested']);
        $this->assertSame(0, $row['requested_days_ago']);
    }

    public function test_paying_him_answers_it(): void
    {
        $this->ask();

        $slip = $this->payHim();

        // The payment is the answer. There is no approve button, because
        // one would write a wage nobody had looked at.
        $request = SalaryPaymentRequest::first();

        $this->assertSame('paid', $request->status);
        $this->assertSame($slip['id'], $request->salary_payment_id);
        $this->assertFalse($this->staffRow()['has_requested']);
    }

    public function test_there_is_no_approve_endpoint(): void
    {
        $id = $this->ask()['id'];

        Sanctum::actingAs($this->owner);

        // Named here so that adding one later is a deliberate act with this
        // test to argue with, rather than a convenience somebody adds
        // because the reject route looked lonely.
        $this->patchJson("/api/v1/salary-requests/{$id}/approve")->assertStatus(404);
    }

    public function test_taking_the_wage_back_leaves_him_asking_again(): void
    {
        $this->ask();
        $slip = $this->payHim();

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/v1/salaries/{$slip['id']}")->assertOk();

        $request = SalaryPaymentRequest::first();

        $this->assertSame('pending', $request->status);
        $this->assertNull($request->salary_payment_id);
        $this->assertTrue($this->staffRow()['has_requested']);
    }

    public function test_it_can_be_turned_down_with_a_reason(): void
    {
        $id = $this->ask()['id'];

        Sanctum::actingAs($this->owner);
        $row = $this->patchJson("/api/v1/salary-requests/{$id}/reject", [
            'decision_note' => 'دوره هنوز تمام نشده، آخر ماه پرداخت می‌شود.',
        ])->assertOk()->json('data');

        $this->assertSame('rejected', $row['status']);
        $this->assertSame('دوره هنوز تمام نشده، آخر ماه پرداخت می‌شود.', $row['decision_note']);
    }

    public function test_turning_it_down_without_a_reason_is_refused(): void
    {
        $id = $this->ask()['id'];

        Sanctum::actingAs($this->owner);

        // Being told no with no reason is worse than not being able to ask.
        $this->patchJson("/api/v1/salary-requests/{$id}/reject")->assertStatus(422);
    }

    public function test_a_worker_cannot_see_everyone_elses(): void
    {
        Sanctum::actingAs($this->baker);

        $this->getJson('/api/v1/salary-requests')->assertForbidden();
    }

    public function test_he_can_see_his_own(): void
    {
        $this->ask();

        Sanctum::actingAs($this->baker);
        $rows = $this->getJson('/api/v1/salary-requests/mine')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('در انتظار پرداخت', $rows[0]['status_label']);
    }
}
