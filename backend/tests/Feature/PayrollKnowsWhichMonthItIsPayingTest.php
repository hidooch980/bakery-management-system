<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Which month a payslip is for, in the form the phone asks the question in.
 *
 * The payroll screen decides who has already been paid by matching payslips
 * against the period its button would write. It used to match against
 * whichever period was newest on file instead — the same period only until
 * a month turns. From the first of the next month everyone paid in the last
 * one reads as already paid, and the screen shuts itself for a month it has
 * not paid a rial of.
 *
 * Nothing goes wrong for a month. Then the payroll quietly stops working
 * and there is no error anywhere to explain why.
 */
class PayrollKnowsWhichMonthItIsPayingTest extends TestCase
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
            'monthly_salary' => 8_000_000,
        ]);
        $this->baker->assignRole('dough_maker');
    }

    private function pay(string $period): array
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson('/api/v1/salaries', [
            'user_id' => $this->baker->id,
            'period_start' => $period,
            'base_amount' => 80_000_000,
            'paid_on' => $period,
        ])->assertCreated()->json('data');
    }

    private function slips(): array
    {
        Sanctum::actingAs($this->owner);

        return $this->getJson('/api/v1/salaries')->assertOk()->json('data.data');
    }

    public function test_a_slip_says_which_jalali_month_it_is_for(): void
    {
        $slip = $this->pay('1405/05/01');

        $this->assertSame('1405/05/01', $slip['period_start_jalali']);
    }

    public function test_the_phone_can_match_its_own_period_against_it(): void
    {
        // Exactly what the screen builds: today's Jalali date with the day
        // replaced by the first. If these two disagree in digits or in
        // padding the comparison silently never matches and the payroll
        // offers to pay the same month twice.
        [$monthStart] = Jalali::currentMonthRange();
        $period = Jalali::date($monthStart);

        $slip = $this->pay($period);

        $this->assertSame($period, $slip['period_start_jalali']);
        $this->assertStringEndsWith('/01', $slip['period_start_jalali']);
    }

    public function test_persian_digits_come_back_as_latin_ones(): void
    {
        // The phone sends the shop's own digits. What comes back has to be
        // in one alphabet or the string compare on the other end is a
        // coin toss.
        $slip = $this->pay('۱۴۰۵/۰۵/۰۱');

        $this->assertSame('1405/05/01', $slip['period_start_jalali']);
    }

    public function test_two_months_are_two_different_periods(): void
    {
        $this->pay('1405/05/01');
        $this->pay('1405/06/01');

        $periods = collect($this->slips())->pluck('period_start_jalali')->all();

        // Newest first, and genuinely distinct. Keying "already paid" off
        // the newest of these is what made the second month unpayable.
        $this->assertSame(['1405/06/01', '1405/05/01'], $periods);
    }

    public function test_the_slip_carries_the_person_it_is_for(): void
    {
        $this->pay('1405/05/01');

        $slip = $this->slips()[0];

        // By id, not only by name. Two people can share a name; the screen
        // marks rows paid by matching this.
        $this->assertSame($this->baker->id, $slip['user']['id']);
    }

    public function test_the_same_month_cannot_be_paid_twice(): void
    {
        $this->pay('1405/05/01');

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/salaries', [
            'user_id' => $this->baker->id,
            'period_start' => '1405/05/01',
            'base_amount' => 80_000_000,
        ])->assertStatus(409);
    }

    public function test_the_month_after_can_be(): void
    {
        $this->pay('1405/05/01');

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/salaries', [
            'user_id' => $this->baker->id,
            'period_start' => '1405/06/01',
            'base_amount' => 80_000_000,
        ])->assertCreated();
    }
}
