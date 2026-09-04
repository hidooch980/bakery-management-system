<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Switching an account off has to switch it off.
 *
 * `is_active` was read in exactly one place — signing in. Every request
 * after that asked only whether the token was valid, and a token outlives
 * the decision that made it. So an account could be marked inactive and
 * the phone holding its session carried on recording sales, reading wages
 * and settling debts, for as long as the token lasted.
 *
 * Two ways in: the panel's own «ویرایش کاربر» sets `is_active` without
 * touching tokens (only the toggle action cleared them), and anything that
 * changes the column outside the API — a console command, a fix applied in
 * the database — clears nothing at all. Both are closed at the door rather
 * than at each of the writes, because the next way in has not been written
 * yet.
 */
class ADeactivatedAccountStopsWorkingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->seller = User::factory()->create([
            'is_active' => true,
            'phone' => '09151234567',
            'password' => 'the-old-one-99',
        ]);
        $this->seller->assignRole('seller');
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('گوشی')->plainTextToken;
    }

    private function as(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_a_live_session_dies_the_moment_the_account_does(): void
    {
        $token = $this->tokenFor($this->seller);

        $this->as($token)->getJson('/api/v1/me')->assertOk();

        // Straight to the column, standing in for every route into it that
        // is not the toggle action: the panel's edit form, a console fix,
        // somebody in the database.
        $this->seller->forceFill(['is_active' => false])->save();

        $this->as($token)->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_the_token_is_not_merely_refused_but_closed(): void
    {
        $token = $this->tokenFor($this->seller);
        $this->seller->forceFill(['is_active' => false])->save();

        $this->as($token)->getJson('/api/v1/me')->assertForbidden();

        // Refusing each request one at a time leaves a live key in a
        // pocket. If the account is off, the session is over.
        $this->assertSame(0, $this->seller->tokens()->count());
    }

    public function test_editing_a_user_inactive_ends_their_session(): void
    {
        $token = $this->tokenFor($this->seller);

        $this->as($this->tokenFor($this->admin))
            ->putJson('/api/v1/users/'.$this->seller->id, ['is_active' => false])
            ->assertOk();

        $this->as($token)->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_an_admin_reset_of_a_password_ends_the_old_sessions(): void
    {
        $token = $this->tokenFor($this->seller);

        // The reason an admin resets somebody's password is usually that
        // the old one is not a secret any more. Leaving the sessions it
        // opened alive answers the wrong half of the problem.
        $this->as($this->tokenFor($this->admin))
            ->putJson('/api/v1/users/'.$this->seller->id, [
                'password' => 'a-new-one-for-him-42',
            ])
            ->assertOk();

        $this->as($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_an_edit_that_changes_neither_leaves_the_session_alone(): void
    {
        $token = $this->tokenFor($this->seller);

        $this->as($this->tokenFor($this->admin))
            ->putJson('/api/v1/users/'.$this->seller->id, ['name' => 'عبدالله'])
            ->assertOk();

        // Correcting a spelling must not sign somebody out mid-shift.
        $this->as($token)->getJson('/api/v1/me')->assertOk();
    }

    public function test_an_inactive_account_still_cannot_sign_in(): void
    {
        $this->seller->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/login', [
            'login' => '09151234567',
            'password' => 'the-old-one-99',
        ])->assertStatus(403);
    }
}
