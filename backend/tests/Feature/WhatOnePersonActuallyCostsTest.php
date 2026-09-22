<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\SalaryPayment;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Models\WorkStart;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «هزینهٔ واقعی هر نفر» and «مقایسهٔ کارکنان».
 *
 * The payroll report has only ever shown net pay — the last line of a
 * payslip, which is not the cost of employing somebody. It is already
 * net of the advances they took, and it says nothing about money that
 * left the shop for them and has not come back.
 *
 * And four reports side by side is not a comparison; it is four reports,
 * with the owner doing the joining in their head.
 */
class WhatOnePersonActuallyCostsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');

        $this->baker = User::factory()->create(['is_active' => true, 'name' => 'شاطر رضا']);
        $this->baker->assignRole('shater');
    }

    public function test_the_cost_is_the_gross_wage_not_the_last_line_of_the_payslip(): void
    {
        // Money handed over before payday. The payslip recovers it, so
        // the last line is short by exactly this much — and the shop has
        // still spent it.
        StaffAdvance::create([
            'user_id' => $this->baker->id,
            'recorded_by' => $this->owner->id,
            'amount' => 2_000_000,
            'paid_on' => now(),
        ]);

        // `net_amount` is worked out by the model from the rows that
        // exist, not taken from here — which is why the advance above is
        // a real row rather than a figure typed into the payslip.
        $payslip = SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => now()->startOfMonth(),
            'period_label' => 'این ماه',
            'base_amount' => 10_000_000,
            'bonus' => 1_000_000,
            'deduction' => 500_000,
        ]);

        $this->assertEqualsWithDelta(8_500_000.0, (float) $payslip->net_amount, 0.01);

        $row = $this->costFor($this->baker);

        // The last line says 8,500,000. The shop spent 10,500,000 on
        // this person: the advance is money that already left, and the
        // payroll report — which shows net and nothing else — has been
        // understating every employee by whatever they drew early.
        $this->assertEqualsWithDelta(8_500_000.0, $row['net_paid'], 0.01);
        $this->assertEqualsWithDelta(10_500_000.0, $row['total'], 0.01);
    }

    public function test_an_advance_no_payslip_has_taken_back_is_cost(): void
    {
        StaffAdvance::create([
            'user_id' => $this->baker->id,
            'recorded_by' => $this->owner->id,
            'amount' => 3_000_000,
            'paid_on' => now(),
        ]);

        $row = $this->costFor($this->baker);

        // Money out of the till with nothing against it yet.
        $this->assertEqualsWithDelta(3_000_000.0, $row['advance_open'], 0.01);
        $this->assertEqualsWithDelta(3_000_000.0, $row['total'], 0.01);
    }

    /**
     * The one mistake this figure exists to avoid.
     *
     * A payslip's gross already contains the advance; counting the
     * advance again on top would charge the shop twice for money it paid
     * once.
     */
    public function test_an_advance_a_payslip_recovered_is_not_counted_twice(): void
    {
        $advance = StaffAdvance::create([
            'user_id' => $this->baker->id,
            'recorded_by' => $this->owner->id,
            'amount' => 2_000_000,
            'paid_on' => now(),
        ]);

        SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => now()->startOfMonth(),
            'period_label' => 'این ماه',
            'base_amount' => 10_000_000,
            'bonus' => 0,
            'deduction' => 0,
            'advance_deduction' => 2_000_000,
            'net_amount' => 8_000_000,
        ]);

        $row = $this->costFor($this->baker);

        $this->assertEqualsWithDelta(0.0, $row['advance_open'], 0.01);
        $this->assertEqualsWithDelta(10_000_000.0, $row['total'], 0.01);

        // Sanity: the advance really was recovered, so the zero above is
        // the rule working rather than the query missing the row.
        $this->assertEqualsWithDelta(0.0, $advance->fresh()->outstanding, 0.01);
    }

    public function test_lateness_is_counted_by_the_day(): void
    {
        foreach (['chane', 'baking'] as $type) {
            WorkStart::create([
                'user_id' => $this->baker->id,
                'type' => $type,
                'date' => now()->toDateString(),
                'started_at' => now(),
                'is_late' => true,
                'late_minutes' => 20,
                'penalty_amount' => 100_000,
            ]);
        }

        // Two late activities, one late day — the tariff charges a day
        // once, and a count of rows would say two.
        $this->assertSame(1, $this->costFor($this->baker)['late_days']);
    }

    public function test_the_staff_are_listed_together_so_they_can_be_compared(): void
    {
        $other = User::factory()->create(['is_active' => true, 'name' => 'چانه‌گیر علی']);
        $other->assignRole('chane_gir');

        SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => now()->startOfMonth(),
            'period_label' => 'این ماه',
            'base_amount' => 10_000_000,
            'net_amount' => 10_000_000,
        ]);

        $body = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/reports/staff')
            ->assertOk()
            ->json('data');

        $names = array_column($body['staff'], 'name');
        $this->assertContains('شاطر رضا', $names);
        $this->assertContains('چانه‌گیر علی', $names);

        // The total has to be the rows, or the list and its footer are
        // two different answers.
        $this->assertEqualsWithDelta(
            $body['total'],
            array_sum(array_column($body['staff'], 'total')),
            0.01,
        );
    }

    public function test_a_period_with_no_payslips_says_so(): void
    {
        $body = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/reports/staff')
            ->assertOk()
            ->json('data');

        // A column of zeroes reads as «nobody was paid» when what it
        // means is «this period has not been run».
        $this->assertNotNull($body['note']);
    }

    public function test_one_persons_page_carries_their_payslips_and_advances(): void
    {
        SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => now()->startOfMonth(),
            'period_label' => 'شهریور',
            'base_amount' => 10_000_000,
            'net_amount' => 10_000_000,
        ]);

        StaffAdvance::create([
            'user_id' => $this->baker->id,
            'recorded_by' => $this->owner->id,
            'amount' => 1_000_000,
            'paid_on' => now(),
        ]);

        $body = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/reports/staff/{$this->baker->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('شاطر رضا', $body['cost']['name']);
        $this->assertCount(1, $body['payslips']);
        $this->assertSame('شهریور', $body['payslips'][0]['period_label']);
        $this->assertCount(1, $body['advances']);
    }

    public function test_staff_cannot_read_what_their_colleagues_cost(): void
    {
        $this->actingAs($this->baker, 'sanctum')
            ->getJson('/api/v1/reports/staff')
            ->assertForbidden();
    }

    // ---------------------------------------------------------- helpers

    private function costFor(User $person): array
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/reports/staff/{$person->id}")
            ->assertOk()
            ->json('data.cost');
    }
}
