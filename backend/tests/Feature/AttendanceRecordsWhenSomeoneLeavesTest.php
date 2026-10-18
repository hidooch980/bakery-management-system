<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * «ثبت خروج»: leaving is recorded once, only after arriving, and the hours
 * worked come back with it.
 */
class AttendanceRecordsWhenSomeoneLeavesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_check_out_after_check_in_returns_the_minutes_worked(): void
    {
        $baker = $this->userWithRole('dough_maker');

        Carbon::setTestNow(Carbon::today()->setTime(6, 0));
        $this->actingAs($baker, 'sanctum')->postJson('/api/v1/attendance/check-in')->assertCreated();

        Carbon::setTestNow(Carbon::today()->setTime(14, 15));
        $this->actingAs($baker, 'sanctum')
            ->postJson('/api/v1/attendance/check-out')
            ->assertOk()
            ->assertJsonPath('data.worked_minutes', 495)
            ->assertJsonPath('data.checked_out_time', '14:15')
            ->assertJsonStructure(['data' => ['id', 'checked_in_at', 'checked_out_at', 'worked_minutes']]);

        $this->actingAs($baker, 'sanctum')
            ->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('data.checked_out', true)
            ->assertJsonPath('data.worked_minutes', 495);

        $this->assertSame('8 ساعت و 15 دقیقه', Attendance::first()->worked_label);
    }

    public function test_check_out_needs_a_check_in_today(): void
    {
        $baker = $this->userWithRole('dough_maker');

        $this->actingAs($baker, 'sanctum')
            ->postJson('/api/v1/attendance/check-out')
            ->assertStatus(422);

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_check_out_is_once_per_day(): void
    {
        $baker = $this->userWithRole('dough_maker');

        $this->actingAs($baker, 'sanctum')->postJson('/api/v1/attendance/check-in')->assertCreated();
        $this->actingAs($baker, 'sanctum')->postJson('/api/v1/attendance/check-out')->assertOk();
        $this->actingAs($baker, 'sanctum')->postJson('/api/v1/attendance/check-out')->assertStatus(409);
    }

    public function test_check_out_cannot_come_before_check_in(): void
    {
        $baker = $this->userWithRole('dough_maker');

        Carbon::setTestNow(Carbon::today()->setTime(9, 0));
        Attendance::create([
            'user_id' => $baker->id,
            'date' => Carbon::today()->toDateString(),
            'checked_in_at' => Carbon::today()->setTime(10, 0),
        ]);

        $this->actingAs($baker, 'sanctum')
            ->postJson('/api/v1/attendance/check-out')
            ->assertStatus(422);

        $this->assertNull(Attendance::first()->checked_out_at);
    }

    public function test_the_seller_records_someone_else_leaving_and_the_roster_shows_it(): void
    {
        $seller = $this->userWithRole('seller');
        $baker = $this->userWithRole('dough_maker');

        Carbon::setTestNow(Carbon::today()->setTime(5, 30));
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/attendance/check-in/{$baker->id}")->assertCreated();

        Carbon::setTestNow(Carbon::today()->setTime(13, 0));
        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/attendance/check-out/{$baker->id}")
            ->assertOk()
            ->assertJsonPath('data.worked_minutes', 450);

        $row = collect($this->actingAs($seller, 'sanctum')->getJson('/api/v1/attendance/roster')
            ->assertOk()->json('data.staff'))->firstWhere('id', $baker->id);

        $this->assertTrue($row['checked_out']);
        $this->assertSame('13:00', $row['checked_out_at']);
        $this->assertSame(450, $row['worked_minutes']);
    }

    public function test_recording_someone_else_leaving_needs_the_same_permission_as_their_arrival(): void
    {
        $baker = $this->userWithRole('dough_maker');
        $other = $this->userWithRole('chane_gir');

        $this->actingAs($other, 'sanctum')->postJson('/api/v1/attendance/check-in')->assertCreated();

        $this->actingAs($baker, 'sanctum')
            ->postJson("/api/v1/attendance/check-out/{$other->id}")
            ->assertForbidden();
    }
}
