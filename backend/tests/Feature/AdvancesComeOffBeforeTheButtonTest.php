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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The advance has to be visible before the button, not after it.
 *
 * A payslip has always taken outstanding advances off — but on the server,
 * during save. Every screen that showed a figure beforehand showed the wage
 * without it: the phone's sheet, the panel's preview. So a wage was agreed
 * at one number and stored at another, and from where the owner sat the
 * advances simply were not being deducted.
 *
 * Nothing about the arithmetic was wrong. What was missing was any way to
 * see it coming, which for a payroll is the same thing.
 */
class AdvancesComeOffBeforeTheButtonTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        // Rial, as this shop is set — the unit the figures cross the wire in.
        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');

        $this->baker = User::factory()->create([
            'is_active' => true,
            'monthly_salary' => 8_000_000,   // Toman: 80,000,000 Rial
        ]);
        $this->baker->assignRole('dough_maker');
    }

    private function advance(float $rial): StaffAdvance
    {
        return StaffAdvance::create([
            'user_id' => $this->baker->id,
            'recorded_by' => $this->owner->id,
            'amount' => Money::toToman($rial),
            'paid_on' => now(),
        ]);
    }

    /** The payroll list exactly as the phone asks for it. */
    private function staffRow(): array
    {
        Sanctum::actingAs($this->owner);

        $list = $this->getJson('/api/v1/salaries/employees')->assertOk()->json('data');

        return collect($list)->firstWhere('id', $this->baker->id);
    }

    private function pay(string $period, float $rial = 80_000_000): array
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson('/api/v1/salaries', [
            'user_id' => $this->baker->id,
            'period_start' => $period,
            'base_amount' => $rial,
            'paid_on' => '1405/06/30',
        ])->assertCreated()->json('data');
    }

    public function test_the_list_carries_what_is_still_owed(): void
    {
        $this->advance(20_000_000);

        $row = $this->staffRow();

        // In Rial, matching the wage beside it. A deduction quoted in a
        // different unit from the wage it comes off is worse than none.
        $this->assertEqualsWithDelta(20_000_000, $row['advance_outstanding'], 0.01);
        $this->assertEqualsWithDelta(80_000_000, $row['monthly_salary'], 0.01);
    }

    public function test_someone_who_owes_nothing_says_zero_rather_than_nothing(): void
    {
        $row = $this->staffRow();

        // Not null and not absent: the phone subtracts this, and a missing
        // field that happens to parse as zero is right by luck.
        $this->assertSame(0.0, (float) $row['advance_outstanding']);
        $this->assertNotEmpty($row['advance_outstanding_formatted']);
    }

    public function test_the_figure_shown_is_the_figure_stored(): void
    {
        $this->advance(20_000_000);

        $row = $this->staffRow();

        // The sum the sheet does, in the order it does it.
        $gross = 80_000_000.0;
        $shown = $gross - min((float) $row['advance_outstanding'], $gross);

        $stored = $this->pay('1405/05/01', $gross);

        $this->assertEqualsWithDelta($shown, $stored['net_amount'], 0.01);
        $this->assertEqualsWithDelta(20_000_000, $stored['advance_deduction'], 0.01);
    }

    public function test_two_advances_are_taken_together(): void
    {
        $this->advance(20_000_000);
        $this->advance(15_000_000);

        $this->assertEqualsWithDelta(35_000_000, $this->staffRow()['advance_outstanding'], 0.01);
    }

    public function test_an_advance_larger_than_the_wage_only_takes_the_wage(): void
    {
        $this->advance(100_000_000);

        $stored = $this->pay('1405/05/01');

        // Not a negative payslip. The wage goes entirely to the advance and
        // the remaining 20,000,000 stands against next month.
        $this->assertEqualsWithDelta(80_000_000, $stored['advance_deduction'], 0.01);
        $this->assertSame(0.0, (float) $stored['net_amount']);

        $this->assertEqualsWithDelta(
            20_000_000,
            Money::convert(StaffAdvance::outstandingFor($this->baker->id)),
            0.01,
        );
    }

    public function test_the_next_month_takes_what_was_left(): void
    {
        $this->advance(100_000_000);

        $this->pay('1405/05/01');
        $this->pay('1405/06/01');

        $slips = SalaryPayment::where('user_id', $this->baker->id)
            ->orderBy('period_start')
            ->get();

        // Stored in Toman: 80,000,000 Rial then the 20,000,000 remainder.
        $this->assertEqualsWithDelta(8_000_000, (float) $slips[0]->advance_deduction, 0.01);
        $this->assertEqualsWithDelta(2_000_000, (float) $slips[1]->advance_deduction, 0.01);
        $this->assertEqualsWithDelta(6_000_000, (float) $slips[1]->net_amount, 0.01);

        $this->assertSame(0.0, StaffAdvance::outstandingFor($this->baker->id));
    }

    public function test_the_list_stops_reporting_a_debt_once_it_is_worked_off(): void
    {
        $this->advance(20_000_000);

        $this->pay('1405/05/01');

        // Next month's sheet must open on the full wage. An advance still
        // being reported after it was recovered would have the owner
        // deducting it a second time by hand.
        $this->assertSame(0.0, (float) $this->staffRow()['advance_outstanding']);
    }

    public function test_deleting_the_payslip_hands_the_advance_back(): void
    {
        $this->advance(20_000_000);

        $id = $this->pay('1405/05/01')['id'];

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/v1/salaries/{$id}")->assertOk();

        // A wage paid in error and taken back has settled nothing, and the
        // next sheet has to open on the debt again.
        $this->assertEqualsWithDelta(
            20_000_000,
            $this->staffRow()['advance_outstanding'],
            0.01,
        );
    }
}
