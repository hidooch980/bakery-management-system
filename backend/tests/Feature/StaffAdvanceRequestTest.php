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
use Tests\TestCase;

/**
 * Asking for pay early, which used to happen in the doorway and left no
 * record of who asked, for how much, or what was said back.
 */
class StaffAdvanceRequestTest extends TestCase
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

    private function ask(float $amount = 500_000, ?User $who = null): StaffAdvanceRequest
    {
        return StaffAdvanceRequest::create([
            'user_id' => ($who ?? $this->baker)->id,
            'amount' => $amount,
            'reason' => 'اجاره خانه',
        ]);
    }

    public function test_an_employee_can_ask_from_their_phone(): void
    {
        $this->actingAs($this->baker, 'sanctum')
            ->postJson('/api/v1/advance-requests', [
                'amount' => 500_000,
                'reason' => 'اجاره خانه',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.user_name', 'رضا');

        $this->assertSame(1, StaffAdvanceRequest::count());
    }

    public function test_asking_is_not_the_same_as_being_paid(): void
    {
        $this->actingAs($this->baker, 'sanctum')
            ->postJson('/api/v1/advance-requests', ['amount' => 500_000])
            ->assertCreated();

        // Nothing has left the till and no payslip is short: a request in
        // the advances table would have done both.
        $this->assertSame(0, StaffAdvance::count());
        $this->assertEquals(0, StaffAdvance::outstandingFor($this->baker->id));
    }

    public function test_the_amount_arrives_in_the_shops_display_unit(): void
    {
        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        $this->actingAs($this->baker, 'sanctum')
            ->postJson('/api/v1/advance-requests', ['amount' => 5_000_000])
            ->assertCreated();

        $this->assertEquals(500_000.0, (float) StaffAdvanceRequest::first()->amount);
    }

    public function test_only_one_request_can_be_open_at_a_time(): void
    {
        $this->ask();

        $this->actingAs($this->baker, 'sanctum')
            ->postJson('/api/v1/advance-requests', ['amount' => 200_000])
            ->assertStatus(409);

        $this->assertSame(1, StaffAdvanceRequest::count());
    }

    public function test_a_settled_request_frees_them_to_ask_again(): void
    {
        $first = $this->ask();
        $first->reject($this->admin, 'این ماه نمی‌شود');

        $this->actingAs($this->baker, 'sanctum')
            ->postJson('/api/v1/advance-requests', ['amount' => 200_000])
            ->assertCreated();
    }

    public function test_an_employee_sees_the_answer_they_were_given(): void
    {
        $request = $this->ask();
        $request->reject($this->admin, 'ماه بعد');

        $response = $this->actingAs($this->baker, 'sanctum')
            ->getJson('/api/v1/advance-requests/mine')
            ->assertOk();

        $this->assertEquals('rejected', $response->json('data.requests.0.status'));
        $this->assertEquals('ماه بعد', $response->json('data.requests.0.decision_note'));
        $this->assertFalse($response->json('data.has_pending'));
    }

    public function test_an_employee_only_sees_their_own_requests(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $this->ask(900_000, $other);
        $this->ask(100_000);

        $response = $this->actingAs($this->baker, 'sanctum')
            ->getJson('/api/v1/advance-requests/mine')
            ->assertOk();

        $this->assertCount(1, $response->json('data.requests'));
        $this->assertEquals(100_000, $response->json('data.requests.0.amount'));
    }

    public function test_approving_hands_over_the_money_and_docks_the_next_payslip(): void
    {
        $request = $this->ask(500_000);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/advance-requests/{$request->id}/approve")
            ->assertOk();

        $this->assertSame(1, StaffAdvance::count());
        $this->assertEquals(500_000.0, (float) StaffAdvance::first()->amount);
        $this->assertEquals(500_000, $response->json('data.outstanding_after'));

        // The two records point at each other, so they cannot drift.
        $this->assertSame(
            StaffAdvance::first()->id,
            $request->fresh()->staff_advance_id,
        );
    }

    public function test_rejecting_hands_over_nothing(): void
    {
        $request = $this->ask(500_000);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/advance-requests/{$request->id}/reject", [
                'note' => 'این ماه نقدینگی نداریم',
            ])
            ->assertOk();

        $this->assertSame(0, StaffAdvance::count());
        $this->assertEquals('rejected', $request->fresh()->status);
    }

    public function test_a_request_cannot_be_answered_twice(): void
    {
        $request = $this->ask(500_000);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/advance-requests/{$request->id}/approve")
            ->assertOk();

        // Otherwise a second yes hands over the money again.
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/advance-requests/{$request->id}/approve")
            ->assertStatus(409);

        $this->assertSame(1, StaffAdvance::count());
    }

    public function test_staff_cannot_answer_requests(): void
    {
        $request = $this->ask();

        $this->actingAs($this->baker, 'sanctum')
            ->patchJson("/api/v1/advance-requests/{$request->id}/approve")
            ->assertForbidden();

        $this->assertSame(0, StaffAdvance::count());
    }

    public function test_staff_cannot_read_everyone_elses_requests(): void
    {
        $this->actingAs($this->baker, 'sanctum')
            ->getJson('/api/v1/advance-requests')
            ->assertForbidden();
    }

    public function test_an_unanswered_request_can_be_withdrawn(): void
    {
        $request = $this->ask();

        $this->actingAs($this->baker, 'sanctum')
            ->deleteJson("/api/v1/advance-requests/{$request->id}")
            ->assertOk();

        $this->assertNull(StaffAdvanceRequest::find($request->id));
    }

    public function test_an_answered_request_cannot_be_withdrawn(): void
    {
        $request = $this->ask();
        $request->approve($this->admin);

        $this->actingAs($this->baker, 'sanctum')
            ->deleteJson("/api/v1/advance-requests/{$request->id}")
            ->assertStatus(409);
    }

    public function test_nobody_can_withdraw_someone_elses_request(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('shater');
        $request = $this->ask(300_000, $other);

        $this->actingAs($this->baker, 'sanctum')
            ->deleteJson("/api/v1/advance-requests/{$request->id}")
            ->assertForbidden();

        $this->assertNotNull(StaffAdvanceRequest::find($request->id));
    }

    public function test_the_manager_list_opens_on_what_is_waiting(): void
    {
        $this->ask(100_000);
        $answered = $this->ask(200_000, User::factory()->create(['is_active' => true]));
        $answered->reject($this->admin, 'نه');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/advance-requests')
            ->assertOk();

        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals(100_000, $response->json('data.data.0.amount'));
    }
}
