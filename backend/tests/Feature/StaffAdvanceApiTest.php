<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\SalaryPayment;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Advances reached the panel and the payslip but never the phone, so the
 * person whose pay was about to be short was the one who could not see it.
 */
class StaffAdvanceApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->baker = User::factory()->create(['is_active' => true, 'name' => 'رضا']);
        $this->baker->assignRole('shater');
    }

    private function advance(User $to, float $amount, ?string $paidOn = null): StaffAdvance
    {
        return StaffAdvance::create([
            'user_id' => $to->id,
            'recorded_by' => $this->admin->id,
            'amount' => $amount,
            'paid_on' => $paidOn ?? now(),
        ]);
    }

    public function test_an_employee_sees_what_will_come_off_their_pay(): void
    {
        $this->advance($this->baker, 500_000);
        $this->advance($this->baker, 300_000);

        $response = $this->actingAs($this->baker, 'sanctum')
            ->getJson('/api/v1/staff-advances/mine')
            ->assertOk();

        $this->assertEquals(800_000, $response->json('data.outstanding'));
        $this->assertCount(2, $response->json('data.advances'));
        $this->assertStringContainsString(
            'کسر می‌شود',
            $response->json('data.summary'),
        );
    }

    public function test_nothing_owed_says_so_rather_than_showing_a_bare_zero(): void
    {
        $response = $this->actingAs($this->baker, 'sanctum')
            ->getJson('/api/v1/staff-advances/mine')
            ->assertOk();

        $this->assertEquals(0, $response->json('data.outstanding'));
        $this->assertStringContainsString(
            'تسویه‌نشده‌ای ندارید',
            $response->json('data.summary'),
        );
    }

    public function test_an_employee_only_sees_their_own(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $this->advance($other, 900_000);
        $this->advance($this->baker, 100_000);

        $response = $this->actingAs($this->baker, 'sanctum')
            ->getJson('/api/v1/staff-advances/mine')
            ->assertOk();

        $this->assertEquals(100_000, $response->json('data.outstanding'));
        $this->assertCount(1, $response->json('data.advances'));
    }

    public function test_a_payslip_deduction_shows_as_recovered(): void
    {
        $this->advance($this->baker, 500_000);

        SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => now()->startOfMonth(),
            'period_label' => 'این ماه',
            'base_amount' => 2_000_000,
            'net_amount' => 2_000_000,
            'paid_on' => now(),
        ]);

        $response = $this->actingAs($this->baker, 'sanctum')
            ->getJson('/api/v1/staff-advances/mine')
            ->assertOk();

        // The payslip took it back, so nothing is left hanging over the
        // next one.
        $this->assertEquals(0, $response->json('data.outstanding'));
        $this->assertEquals(500_000, $response->json('data.advances.0.recovered'));
        $this->assertTrue($response->json('data.advances.0.is_settled'));
    }

    public function test_an_advance_is_recorded_in_the_shops_display_unit(): void
    {
        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/staff-advances', [
                'user_id' => $this->baker->id,
                'amount' => 5_000_000, // Rial
            ])
            ->assertCreated();

        // Stored in Toman, a tenth of what was typed - the error that hit
        // expenses, payslips and the flour price before this.
        $this->assertEquals(
            500_000.0,
            (float) StaffAdvance::first()->amount,
        );
    }

    public function test_staff_cannot_hand_themselves_an_advance(): void
    {
        $this->actingAs($this->baker, 'sanctum')
            ->postJson('/api/v1/staff-advances', [
                'user_id' => $this->baker->id,
                'amount' => 5_000_000,
            ])
            ->assertForbidden();
    }

    public function test_staff_cannot_read_everyone_elses(): void
    {
        $this->actingAs($this->baker, 'sanctum')
            ->getJson('/api/v1/staff-advances')
            ->assertForbidden();
    }

    public function test_the_payroll_screen_lists_who_owes_what(): void
    {
        $this->advance($this->baker, 400_000);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/staff-advances/outstanding')
            ->assertOk();

        $this->assertEquals(400_000, $response->json('data.total'));
        $this->assertEquals('رضا', $response->json('data.employees.0.user_name'));
    }

    public function test_someone_with_nothing_outstanding_is_left_off_the_list(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/staff-advances/outstanding')
            ->assertOk();

        $this->assertSame([], $response->json('data.employees'));
        $this->assertEquals(0, $response->json('data.total'));
    }

    public function test_an_untouched_advance_can_be_deleted(): void
    {
        $advance = $this->advance($this->baker, 200_000);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/staff-advances/{$advance->id}")
            ->assertOk();

        $this->assertNull(StaffAdvance::find($advance->id));
    }

    public function test_one_a_payslip_has_taken_back_cannot_be_deleted(): void
    {
        $advance = $this->advance($this->baker, 200_000);

        SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => now()->startOfMonth(),
            'period_label' => 'این ماه',
            'base_amount' => 2_000_000,
            'net_amount' => 2_000_000,
            'paid_on' => now(),
        ]);

        // Deleting it would leave that deduction pointing at nothing, and
        // the employee short by the amount.
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/staff-advances/{$advance->id}")
            ->assertStatus(409);

        $this->assertNotNull(StaffAdvance::find($advance->id));
    }
}
