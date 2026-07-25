<?php

namespace Tests\Feature;

use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the dough -> chane -> sale chain, attendance, and role boundaries
 * through the public API surface.
 */
class BakeryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        // The chane weights the formula derives from are shop settings.
        \App\Models\Bakery::first()->update([
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
        ]);
    }

    public function test_login_returns_token_and_roles(): void
    {
        $user = $this->userWithRole('seller');

        $this->postJson('/api/v1/login', [
            'login' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'roles', 'permissions']]]);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = $this->userWithRole('seller');
        $user->update(['is_active' => false]);

        $this->postJson('/api/v1/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->assertForbidden();
    }

    public function test_full_production_chain(): void
    {
        $dough = $this->userWithRole('dough_maker');
        $chane = $this->userWithRole('chane_gir');
        $seller = $this->userWithRole('seller');

        // 1. Dough maker records bags.
        $this->actingAs($dough, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 10])
            ->assertCreated();

        $doughEntry = DoughEntry::first();
        $this->assertSame('pending', $doughEntry->status);

        // 2. Chane gir sees it in the queue and records chane.
        $this->actingAs($chane, 'sanctum')
            ->getJson('/api/v1/dough-entries/pending')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->actingAs($chane, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $doughEntry->id,
                'chane_count' => 300,
                'nanino_chane_count' => 60,
                'spray_flour_kg' => 4.5,
            ])
            ->assertCreated()
            // 300 x 0.85 normal plus 60 x 1.0 nanino.
            ->assertJsonPath('data.total_weight_kg', 315);

        $this->assertSame('processed', $doughEntry->fresh()->status);

        // Spray flour is deducted from stock automatically.
        $this->assertDatabaseHas('flour_stock_movements', ['type' => 'out', 'amount_kg' => 4.5]);

        // 3. Seller sells the chane.
        $chaneEntry = ChaneEntry::first();

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chaneEntry->id,
                'payment_type' => 'card',
                'amount' => 500000,
            ])
            ->assertCreated();

        $this->assertSame('sold', $chaneEntry->fresh()->status);
    }

    public function test_dough_cannot_be_processed_twice(): void
    {
        $dough = $this->userWithRole('dough_maker');
        $chane = $this->userWithRole('chane_gir');

        $this->actingAs($dough, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 5]);

        $payload = [
            'dough_entry_id' => DoughEntry::first()->id,
            'chane_count' => 100,
            'spray_flour_kg' => 1,
        ];

        $this->actingAs($chane, 'sanctum')->postJson('/api/v1/chane-entries', $payload)->assertCreated();
        $this->actingAs($chane, 'sanctum')->postJson('/api/v1/chane-entries', $payload)->assertStatus(409);
    }

    public function test_attendance_check_in_is_once_per_day(): void
    {
        $user = $this->userWithRole('dough_maker');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/attendance/check-in')
            ->assertCreated()
            ->assertJsonStructure(['data' => ['checked_in_at']]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/attendance/check-in')
            ->assertStatus(409);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('data.checked_in', true);
    }

    public function test_admin_sees_attendance_times(): void
    {
        $staff = $this->userWithRole('dough_maker');
        $admin = $this->userWithRole('admin');

        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/attendance/check-in');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/attendance')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_only_admin_can_create_accounts(): void
    {
        foreach (['dough_maker', 'chane_gir', 'seller'] as $role) {
            $this->actingAs($this->userWithRole($role), 'sanctum')
                ->postJson('/api/v1/users', [
                    'name' => 'x',
                    'email' => "x-{$role}@test.com",
                    'password' => 'password123',
                    'role' => 'seller',
                ])
                ->assertForbidden();
        }

        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'کارمند جدید',
                'email' => 'new@test.com',
                'password' => 'password123',
                'role' => 'seller',
            ])
            ->assertCreated();
    }

    public function test_role_boundaries_are_enforced(): void
    {
        $seller = $this->userWithRole('seller');
        $doughMaker = $this->userWithRole('dough_maker');
        $chaneGir = $this->userWithRole('chane_gir');

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 1])->assertForbidden();

        $this->actingAs($doughMaker, 'sanctum')
            ->postJson('/api/v1/chane-entries', [])->assertForbidden();

        $this->actingAs($chaneGir, 'sanctum')
            ->postJson('/api/v1/sales', [])->assertForbidden();

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/reports/dashboard')->assertForbidden();

        $this->actingAs($doughMaker, 'sanctum')
            ->putJson('/api/v1/bakery', ['name' => 'hack'])->assertForbidden();
    }

    public function test_password_change_revokes_tokens(): void
    {
        $user = $this->userWithRole('seller');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/change-password', [
                'current_password' => 'wrong',
                'new_password' => 'NewPass@123',
                'new_password_confirmation' => 'NewPass@123',
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/change-password', [
                'current_password' => 'password',
                'new_password' => 'NewPass@123',
                'new_password_confirmation' => 'NewPass@123',
            ])
            ->assertOk();

        $this->postJson('/api/v1/login', [
            'login' => $user->email,
            'password' => 'NewPass@123',
        ])->assertOk();
    }

    public function test_bakery_settings_are_readable_by_all_staff(): void
    {
        \App\Models\Bakery::first()->update([
            'normal_chane_weight_kg' => 0.430,
            'nanino_chane_weight_kg' => 0.380,
            'bread_price' => 3000,
        ]);

        foreach (['dough_maker', 'chane_gir', 'seller'] as $role) {
            $this->actingAs($this->userWithRole($role), 'sanctum')
                ->getJson('/api/v1/bakery')
                ->assertOk()
                ->assertJsonPath('data.normal_chane_weight_kg', '0.430')
                ->assertJsonPath('data.nanino_chane_weight_kg', '0.380')
                ->assertJsonPath('data.bread_price', '3000.00');
        }
    }

    public function test_admin_can_update_bakery_settings(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->putJson('/api/v1/bakery', [
                'name' => 'نانوایی تست',
                'normal_chane_weight_kg' => 0.5,
                'nanino_chane_weight_kg' => 0.45,
                'bread_price' => 4000,
            ])
            ->assertOk();

        $this->assertDatabaseHas('bakeries', [
            'name' => 'نانوایی تست',
            'bread_price' => 4000,
        ]);
    }

    public function test_bakery_settings_reject_invalid_values(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->putJson('/api/v1/bakery', [
                'name' => 'نانوایی تست',
                'normal_chane_weight_kg' => -1,
                'bread_price' => 'رایگان',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['normal_chane_weight_kg', 'bread_price']);
    }

    public function test_api_error_envelope_is_consistent(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonStructure(['success', 'message', 'errors']);

        $this->postJson('/api/v1/login', ['login' => 'nobody@test.com', 'password' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
