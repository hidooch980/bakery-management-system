<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\StaffAdjustment;
use App\Models\StaffPenaltyRequest;
use App\Models\User;
use App\Models\WorkStart;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seller asks for a penalty on anyone; nothing is docked until the
 * manager approves, and an approved one is cancelled by waiving, never
 * by deleting.
 */
class APenaltyIsOnlyTakenOnceTheManagerSaysYesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

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
        $this->seller = User::factory()->create(['is_active' => true, 'name' => 'مریم']);
        $this->seller->assignRole('seller');
        $this->baker = User::factory()->create(['is_active' => true, 'name' => 'علی']);
        $this->baker->assignRole('shater');
    }

    private function ask(array $over = []): int
    {
        return $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/penalty-requests', array_merge([
                'user_id' => $this->baker->id,
                'type' => 'fine',
                'reason' => 'late',
                'amount' => 500_000,
                'note' => 'دیر آمد',
            ], $over))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');
    }

    public function test_the_seller_can_ask_and_nothing_is_docked_yet(): void
    {
        $this->ask();

        $this->assertSame(1, StaffPenaltyRequest::count());
        $this->assertSame(0, StaffAdjustment::count());
    }

    public function test_the_seller_can_name_any_staff_member_including_another_seller_and_the_admin(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('seller');

        $ids = collect($this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/penalty-requests/options')->assertOk()->json('data.staff'))->pluck('id');

        $this->assertTrue($ids->contains($other->id));
        $this->assertTrue($ids->contains($this->baker->id));
        $this->assertTrue($ids->contains($this->admin->id));
        $this->assertFalse($ids->contains($this->seller->id));
    }

    public function test_a_fine_needs_an_amount_and_nobody_penalises_themselves(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/penalty-requests', ['user_id' => $this->baker->id, 'type' => 'fine', 'reason' => 'late'])
            ->assertUnprocessable();
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/penalty-requests', ['user_id' => $this->seller->id, 'type' => 'warning', 'reason' => 'late'])
            ->assertUnprocessable();
    }

    public function test_a_baker_cannot_ask_and_a_seller_cannot_approve(): void
    {
        $this->actingAs($this->baker, 'sanctum')
            ->postJson('/api/v1/penalty-requests', ['user_id' => $this->seller->id, 'type' => 'warning', 'reason' => 'late'])
            ->assertForbidden();

        $id = $this->ask();
        $this->actingAs($this->seller, 'sanctum')
            ->patchJson("/api/v1/penalty-requests/{$id}/approve")->assertForbidden();
        $this->assertSame(0, StaffAdjustment::count());
    }

    public function test_approving_a_fine_writes_one_penalty_the_payslip_counts(): void
    {
        $id = $this->ask();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/penalty-requests/{$id}/approve", ['amount' => 400_000])
            ->assertOk()->assertJsonPath('data.request.status', 'approved');

        // A double tap answers 409 and writes nothing more.
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/penalty-requests/{$id}/approve")->assertStatus(409);

        $adj = StaffAdjustment::sole();
        $this->assertSame(StaffAdjustment::PENALTY, $adj->kind);
        $this->assertSame(StaffAdjustment::BY_AMOUNT, $adj->basis);
        $this->assertEquals(400_000, $adj->value);
        $this->assertSame($this->baker->id, $adj->user_id);
        $this->assertSame(1, StaffAdjustment::counted()->count());
    }

    public function test_an_approved_warning_is_on_the_record_and_worth_nothing(): void
    {
        $id = $this->ask(['type' => 'warning', 'amount' => null]);
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/penalty-requests/{$id}/approve")->assertOk();

        $adj = StaffAdjustment::sole();
        $this->assertSame(StaffAdjustment::BY_NOTE, $adj->basis);
        $this->assertEquals(0, $adj->value);
    }

    public function test_a_rejection_needs_a_reason_and_docks_nothing(): void
    {
        $id = $this->ask();
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/penalty-requests/{$id}/reject")->assertUnprocessable();
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/penalty-requests/{$id}/reject", ['note' => 'مرخصی داشت'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(0, StaffAdjustment::count());
        $this->actingAs($this->seller, 'sanctum')->getJson('/api/v1/penalty-requests/mine')
            ->assertOk()->assertJsonPath('data.requests.0.decision_note', 'مرخصی داشت');
    }

    public function test_cancelling_waives_and_never_deletes(): void
    {
        $id = $this->ask();
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/penalty-requests/{$id}/approve")->assertOk();
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/penalty-requests/{$id}/cancel")
            ->assertOk()->assertJsonPath('data.is_cancelled', true);

        $this->assertSame(1, StaffAdjustment::count());
        $this->assertSame(0, StaffAdjustment::counted()->count());
        $this->assertSame(1, StaffPenaltyRequest::count());
    }

    public function test_a_late_day_only_warns_and_does_not_block(): void
    {
        WorkStart::query()->insert([
            'bakery_id' => Bakery::first()->id,
            'user_id' => $this->baker->id,
            'date' => now()->toDateString(),
            'type' => 'dough',
            'started_at' => now(),
            'is_late' => true,
            'late_minutes' => 45,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/penalty-requests', [
                'user_id' => $this->baker->id, 'type' => 'fine', 'reason' => 'late', 'amount' => 100_000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.late_minutes_that_day', 45);
    }

    public function test_the_seller_withdraws_only_their_own_pending_request(): void
    {
        $id = $this->ask();
        $this->actingAs($this->seller, 'sanctum')->deleteJson("/api/v1/penalty-requests/{$id}")->assertOk();
        $this->assertSame(0, StaffPenaltyRequest::count());
    }
}
