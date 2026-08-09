<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The floor arrives with flour on their hands and phones in a locker, so
 * the seller enters their arrival for them.
 *
 * The record has to say who entered it. A sheet where a tick someone made
 * for you looks the same as one you made yourself proves nothing about who
 * was actually in the shop.
 */
class TickingTheFloorInTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        $this->baker = User::factory()->create(['is_active' => true]);
        $this->baker->assignRole('shater');
    }

    public function test_the_seller_ticks_in_someone_who_is_not_holding_a_phone(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/attendance/check-in/{$this->baker->id}")
            ->assertCreated();

        $this->assertDatabaseHas('attendances', [
            'user_id' => $this->baker->id,
            'recorded_by' => $this->seller->id,
        ]);
    }

    public function test_a_tick_you_made_yourself_names_nobody_else(): void
    {
        $this->actingAs($this->baker, 'sanctum')
            ->postJson('/api/v1/attendance/check-in')
            ->assertCreated();

        // The two cases have to stay tellable apart.
        $this->assertNull(Attendance::first()->recorded_by);
        $this->assertFalse(Attendance::first()->was_recorded_by_another);
    }

    public function test_the_same_person_cannot_be_ticked_in_twice(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/attendance/check-in/{$this->baker->id}")
            ->assertCreated();

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/attendance/check-in/{$this->baker->id}")
            ->assertStatus(409);

        $this->assertSame(1, Attendance::count());
    }

    public function test_someone_who_already_ticked_themselves_in_is_not_doubled(): void
    {
        $this->actingAs($this->baker, 'sanctum')
            ->postJson('/api/v1/attendance/check-in')
            ->assertCreated();

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/attendance/check-in/{$this->baker->id}")
            ->assertStatus(409);

        // And the seller's later attempt does not overwrite who made it.
        $this->assertNull(Attendance::first()->recorded_by);
    }

    public function test_a_staff_member_who_left_cannot_be_ticked_in(): void
    {
        $this->baker->update(['is_active' => false]);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/attendance/check-in/{$this->baker->id}")
            ->assertStatus(422);

        $this->assertSame(0, Attendance::count());
    }

    public function test_the_roster_says_who_is_in_and_who_is_not(): void
    {
        $response = $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/attendance/roster')
            ->assertOk();

        // The seller is not offered themselves — they have their own tick.
        $ids = collect($response->json('data.staff'))->pluck('id');
        $this->assertTrue($ids->contains($this->baker->id));
        $this->assertFalse($ids->contains($this->seller->id));

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/attendance/check-in/{$this->baker->id}");

        $after = $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/attendance/roster')
            ->json('data.staff');

        $this->assertTrue(collect($after)->firstWhere('id', $this->baker->id)['checked_in']);
    }

    public function test_the_floor_cannot_tick_each_other_in(): void
    {
        // Only the seller and the admin hold this. A baker marking the
        // dough maker in would make the sheet worthless.
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('dough_maker');

        $this->actingAs($this->baker, 'sanctum')
            ->postJson("/api/v1/attendance/check-in/{$other->id}")
            ->assertForbidden();

        $this->actingAs($this->baker, 'sanctum')
            ->getJson('/api/v1/attendance/roster')
            ->assertForbidden();
    }
}
