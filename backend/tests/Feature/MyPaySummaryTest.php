<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\SalaryPayment;
use App\Models\StaffAdvance;
use App\Models\StaffAdvanceRequest;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a member of staff is owed, on their own phone.
 *
 * Their pay was the one figure in the shop they could not see: it lived in
 * a book in the office and on a screen only the admin opened, so the way to
 * find out what was left of your month was to ask. Asking is how a wrong
 * figure survives for months.
 */
class MyPaySummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $this->baker = User::factory()->create([
            'is_active' => true,
            'name' => 'رضا',
            'monthly_salary' => 15_000_000,
        ]);
        $this->baker->assignRole('shater');
    }

    private function summary(?User $who = null): array
    {
        return $this->actingAs($who ?? $this->baker)
            ->getJson('/api/v1/salaries/my-summary')
            ->assertOk()
            ->json('data');
    }

    private function advance(float $amount): StaffAdvance
    {
        return StaffAdvance::create([
            'user_id' => $this->baker->id,
            'amount' => $amount,
            'paid_on' => now(),
        ]);
    }

    public function test_it_reports_the_wage_on_record(): void
    {
        $data = $this->summary();

        $this->assertEquals(15_000_000, $data['monthly_salary']);
        $this->assertEquals(15_000_000, $data['remaining']);
        $this->assertEquals(0, $data['advance_outstanding']);
    }

    public function test_an_advance_comes_off_what_is_left_of_the_month(): void
    {
        $this->advance(4_000_000);

        $data = $this->summary();

        $this->assertEquals(4_000_000, $data['advance_outstanding']);
        $this->assertEquals(11_000_000, $data['remaining']);
        $this->assertStringContainsString(Money::format(11_000_000), $data['summary']);
    }

    public function test_drawing_more_than_a_months_wage_does_not_report_a_negative_one(): void
    {
        $this->advance(18_000_000);

        $data = $this->summary();

        // A payslip would recover no more than the pay itself, so a negative
        // remainder would contradict what settling actually does.
        $this->assertEquals(0, $data['remaining']);
        $this->assertTrue($data['carries_over']);

        // And it is not promised to next month's payslip: this shop issues
        // none, so the debt simply stands.
        $this->assertStringContainsString('بدهی', $data['summary']);
        $this->assertStringNotContainsString('ماه بعد', $data['summary']);
    }

    public function test_an_issued_payslip_that_is_unpaid_is_reported_outright(): void
    {
        SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => now()->startOfMonth(),
            'base_amount' => 15_000_000,
            'paid_on' => null,
        ]);

        $data = $this->summary();

        // A different kind of truth from the forecast: this one the shop has
        // already accepted it owes.
        $this->assertSame(1, $data['unpaid_payslips_count']);
        $this->assertEquals(15_000_000, $data['unpaid_payslips_total']);
        $this->assertStringContainsString('پرداخت‌نشده', $data['summary']);
    }

    public function test_a_paid_payslip_is_not_still_owed(): void
    {
        SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => now()->startOfMonth(),
            'base_amount' => 15_000_000,
            'paid_on' => now(),
        ]);

        $data = $this->summary();

        $this->assertSame(0, $data['unpaid_payslips_count']);
        $this->assertEquals(0, $data['unpaid_payslips_total']);
    }

    public function test_a_wage_nobody_has_set_says_so_rather_than_reading_zero(): void
    {
        $this->baker->update(['monthly_salary' => null]);

        $data = $this->summary();

        // Zero would read as "you are paid nothing", which is a different
        // statement from "nobody has entered this yet".
        $this->assertNull($data['monthly_salary']);
        $this->assertNull($data['remaining']);
        $this->assertStringContainsString('ثبت نشده', $data['summary']);
    }

    public function test_it_says_when_a_request_is_already_waiting(): void
    {
        $this->assertFalse($this->summary()['has_pending_request']);

        StaffAdvanceRequest::create([
            'user_id' => $this->baker->id,
            'amount' => 500_000,
        ]);

        // So the card can say so instead of offering a button the server is
        // about to refuse: one open request at a time.
        $this->assertTrue($this->summary()['has_pending_request']);
    }

    public function test_one_persons_figures_are_never_another_persons(): void
    {
        $other = User::factory()->create([
            'is_active' => true,
            'monthly_salary' => 9_000_000,
        ]);
        $other->assignRole('dough_maker');

        $this->advance(4_000_000);

        $this->assertEquals(0, $this->summary($other)['advance_outstanding']);
        $this->assertEquals(9_000_000, $this->summary($other)['monthly_salary']);
    }

    public function test_the_figures_are_in_the_shops_display_unit(): void
    {
        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        $this->advance(4_000_000); // Toman, as stored

        $data = $this->summary();

        // Ten Rial to the Toman. The owner reads Rial, and quoting Toman
        // once had him seeing a missing zero that was not there.
        $this->assertEquals(150_000_000, $data['monthly_salary']);
        $this->assertEquals(40_000_000, $data['advance_outstanding']);
        $this->assertEquals(110_000_000, $data['remaining']);
    }

    public function test_every_role_can_read_their_own_pay(): void
    {
        foreach (['dough_maker', 'chane_gir', 'seller', 'admin'] as $role) {
            $user = User::factory()->create([
                'is_active' => true,
                'monthly_salary' => 8_000_000,
            ]);
            $user->assignRole($role);

            $this->actingAs($user)
                ->getJson('/api/v1/salaries/my-summary')
                ->assertOk();
        }
    }

    public function test_a_stranger_is_refused(): void
    {
        $this->getJson('/api/v1/salaries/my-summary')->assertUnauthorized();
    }
}
