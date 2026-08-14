<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\StaffAdvance;
use App\Models\StaffAdvanceRequest;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the manager knows at the moment of granting an advance.
 *
 * The consequence was reported after approving — by which time the money is
 * out, which is the wrong moment to learn that this person had already drawn
 * most of their month.
 */
class AdvanceRequestShowsWhereTheyStandTest extends TestCase
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

        $this->baker = User::factory()->create([
            'is_active' => true,
            'name' => 'رضا',
            'monthly_salary' => 15_000_000,
        ]);
        $this->baker->assignRole('shater');
    }

    private function ask(float $amount = 2_000_000, ?User $who = null): StaffAdvanceRequest
    {
        return StaffAdvanceRequest::create([
            'user_id' => ($who ?? $this->baker)->id,
            'amount' => $amount,
        ]);
    }

    private function pending(): array
    {
        return $this->actingAs($this->admin)
            ->getJson('/api/v1/advance-requests')
            ->assertOk()
            ->json('data.data');
    }

    public function test_the_manager_sees_what_the_asker_already_owes(): void
    {
        StaffAdvance::create([
            'user_id' => $this->baker->id,
            'amount' => 5_000_000,
            'paid_on' => now(),
        ]);

        $this->ask(2_000_000);

        $row = $this->pending()[0];

        $this->assertEquals(5_000_000, $row['outstanding']);
        $this->assertSame(Money::format(7_000_000), $row['total_after_formatted']);
        $this->assertSame(Money::format(15_000_000), $row['monthly_salary_formatted']);
        $this->assertFalse($row['exceeds_salary']);
    }

    public function test_it_warns_when_granting_would_pass_a_months_wage(): void
    {
        StaffAdvance::create([
            'user_id' => $this->baker->id,
            'amount' => 14_000_000,
            'paid_on' => now(),
        ]);

        $this->ask(2_000_000);

        // Not a refusal — the shop does lend past a month — but not
        // something to find out afterwards either.
        $this->assertTrue($this->pending()[0]['exceeds_salary']);
    }

    public function test_a_wage_nobody_has_set_cannot_be_exceeded(): void
    {
        $this->baker->update(['monthly_salary' => null]);

        $this->ask(90_000_000);

        $row = $this->pending()[0];

        // Null is not zero: an unset wage means nobody has said what this
        // person earns, so no claim can be made about passing it.
        $this->assertNull($row['monthly_salary_formatted']);
        $this->assertFalse($row['exceeds_salary']);
    }

    public function test_someone_elses_advances_are_not_counted_against_them(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('dough_maker');

        StaffAdvance::create([
            'user_id' => $other->id,
            'amount' => 9_000_000,
            'paid_on' => now(),
        ]);

        $this->ask(1_000_000);

        $this->assertEquals(0, $this->pending()[0]['outstanding']);
    }

    public function test_the_standing_is_read_once_per_person_not_once_per_row(): void
    {
        // The employee's own history is many rows for one person, and the
        // manager's list is one row per person. Working it out per row would
        // be a query per row in both.
        foreach (range(1, 4) as $i) {
            $request = $this->ask(500_000);
            $request->update(['status' => StaffAdvanceRequest::REJECTED]);
        }

        DB::enableQueryLog();

        $this->actingAs($this->baker)
            ->getJson('/api/v1/advance-requests/mine')
            ->assertOk();

        $reads = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'staff_advances'))
            ->count();

        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $reads);
    }

    public function test_the_figures_are_in_the_shops_display_unit(): void
    {
        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        StaffAdvance::create([
            'user_id' => $this->baker->id,
            'amount' => 5_000_000, // Toman, as stored
            'paid_on' => now(),
        ]);

        $this->ask(2_000_000);

        $this->assertEquals(50_000_000, $this->pending()[0]['outstanding']);
    }

    public function test_the_asker_sees_their_own_standing_too(): void
    {
        StaffAdvance::create([
            'user_id' => $this->baker->id,
            'amount' => 3_000_000,
            'paid_on' => now(),
        ]);

        $this->ask(1_000_000);

        $rows = $this->actingAs($this->baker)
            ->getJson('/api/v1/advance-requests/mine')
            ->assertOk()
            ->json('data.requests');

        $this->assertEquals(3_000_000, $rows[0]['outstanding']);
    }
}
