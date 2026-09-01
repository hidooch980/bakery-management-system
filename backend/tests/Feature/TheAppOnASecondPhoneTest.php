<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Signing in on one phone must not sign the others out.
 *
 * It used to. `login()` deleted every existing token, so a session lived
 * on exactly one device and opening the app anywhere else killed the
 * first — silently, because nothing in the app acted on a 401. It looked
 * like a broken feature: on 1405/06/11 the owner's phone made 96 refused
 * requests to the nanino endpoints while `/me` had answered 200 four
 * seconds earlier on the same key.
 *
 * The cap stays, because unlimited forgotten keys is the other failure —
 * see the PruneIdleTokens command, written for exactly that.
 */
class TheAppOnASecondPhoneTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->user = User::factory()->create([
            'phone' => '09151234567',
            'password' => 'a-good-long-password',
            'is_active' => true,
        ]);
        $this->user->assignRole('admin');
    }

    private function signIn(): string
    {
        return $this->postJson('/api/v1/login', [
            'login' => '09151234567',
            'password' => 'a-good-long-password',
        ])->assertOk()->json('data.token');
    }

    public function test_signing_in_on_a_second_phone_leaves_the_first_working(): void
    {
        $first = $this->signIn();

        // The same person opens the app on another handset.
        $this->signIn();

        // The first phone is still signed in. This is the whole bug: it
        // used to answer 401 here, and the app never said why.
        $this->withHeader('Authorization', 'Bearer '.$first)
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    public function test_a_third_phone_is_still_fine(): void
    {
        $first = $this->signIn();
        $second = $this->signIn();
        $this->signIn();

        foreach ([$first, $second] as $token) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson('/api/v1/me')
                ->assertOk();
        }
    }

    public function test_a_fourth_sign_in_retires_the_oldest_only(): void
    {
        $first = $this->signIn();
        $second = $this->signIn();
        $third = $this->signIn();
        $fourth = $this->signIn();

        // The oldest key is closed rather than all of them: a forgotten
        // token is the one whose loss nobody notices.
        $this->withHeader('Authorization', 'Bearer '.$first)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();

        foreach ([$second, $third, $fourth] as $token) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson('/api/v1/me')
                ->assertOk();
        }

        $this->assertSame(3, $this->user->tokens()->count());
    }

    public function test_a_password_change_still_signs_every_device_out(): void
    {
        $this->signIn();
        $firstId = $this->user->tokens()->orderBy('id')->value('id');
        $second = $this->signIn();

        $this->withHeader('Authorization', 'Bearer '.$second)
            ->postJson('/api/v1/change-password', [
                'current_password' => 'a-good-long-password',
                'new_password' => 'another-good-long-one',
                'new_password_confirmation' => 'another-good-long-one',
            ])->assertOk();

        // Deliberate and untouched: if somebody else knew the old
        // password, this is the moment they stop being able to use it.
        //
        // The count is the claim, as it is in the reset test next door.
        // Re-requesting with $first inside the same test proves nothing:
        // the container keeps the user resolved by the change-password
        // call above, so it answers 200 for a key that no longer exists.
        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertNull(
            $this->user->tokens()->where('id', $firstId)->first(),
            "the other device's key is gone",
        );
    }
}
