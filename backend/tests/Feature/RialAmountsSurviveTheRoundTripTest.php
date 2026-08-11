<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\Expense;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Amounts are stored in Toman and shown in whatever unit the shop is set to.
 * Costs and payslips were being written straight from the request without
 * that conversion, so a shop working in Rial saw every figure come back one
 * zero longer than it typed it — and the stored value really was ten times
 * the truth, which reached the reports and the profit split.
 */
class RialAmountsSurviveTheRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => Money::RIAL]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    protected function tearDown(): void
    {
        Money::forgetCache();
        parent::tearDown();
    }

    public function test_a_cost_entered_in_rial_reads_back_as_the_same_figure(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/expenses', [
                'category' => array_key_first(Expense::CATEGORIES),
                'title' => 'آرد',
                'amount' => 1_000_000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount', 1_000_000.0);
    }

    public function test_and_is_stored_in_toman_underneath(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/expenses', [
                'category' => array_key_first(Expense::CATEGORIES),
                'title' => 'آرد',
                'amount' => 1_000_000,
            ])
            ->assertCreated();

        // Ten Rial to the Toman. Storing the Rial figure raw would have put
        // 1,000,000 here and inflated every report built on it.
        $this->assertEquals(100_000.0, (float) Expense::first()->amount);
    }

    public function test_editing_a_cost_does_not_multiply_it_again(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/expenses', [
                'category' => array_key_first(Expense::CATEGORIES),
                'title' => 'آرد',
                'amount' => 1_000_000,
            ])
            ->assertCreated();

        $expense = Expense::first();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/expenses/{$expense->id}", ['amount' => 2_000_000])
            ->assertOk()
            ->assertJsonPath('data.amount', 2_000_000.0);

        $this->assertEquals(200_000.0, (float) $expense->fresh()->amount);
    }

    public function test_a_payslip_entered_in_rial_reads_back_the_same(): void
    {
        $staff = User::factory()->create(['is_active' => true]);
        $staff->assignRole('seller');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/salaries', [
                'user_id' => $staff->id,
                'period_start' => '1405/05/01',
                'base_amount' => 50_000_000,
                'bonus' => 5_000_000,
            ])
            ->assertCreated();

        $payment = SalaryPayment::first();

        $this->assertEquals(5_000_000.0, (float) $payment->base_amount);
        $this->assertEquals(500_000.0, (float) $payment->bonus);
    }

    public function test_a_toman_shop_is_left_exactly_as_it_was(): void
    {
        Bakery::first()->update(['currency' => Money::TOMAN]);
        Money::forgetCache();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/expenses', [
                'category' => array_key_first(Expense::CATEGORIES),
                'title' => 'آرد',
                'amount' => 1_000_000,
            ])
            ->assertCreated();

        // No conversion either way when the display unit is the stored one.
        $this->assertEquals(1_000_000.0, (float) Expense::first()->amount);
    }
}
