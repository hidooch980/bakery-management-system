<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The device list named the phone and never the build on it.
 *
 * So «کار نکرد» from the shop floor could not be told apart from «کار
 * نکرد, on a build from three releases ago», and four releases in a row
 * went into fixing things that may not have been on the handset doing the
 * complaining.
 */
class ASessionSaysWhichBuildItIsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('seller');
    }

    private function version(): ?string
    {
        return $this->user->tokens()->latest('id')->first()?->app_version;
    }

    public function test_signing_in_records_the_build_that_asked(): void
    {
        $this->withHeader('X-App-Version', '5.2.0')
            ->postJson('/api/v1/login', [
                'login' => $this->user->email,
                'password' => 'password',
            ])
            ->assertOk();

        // At sign-in as well as in the middleware, so the list is right
        // from the first request rather than the second.
        $this->assertSame('5.2.0', $this->version());
    }

    public function test_an_app_that_sends_nothing_still_signs_in(): void
    {
        $this->postJson('/api/v1/login', [
            'login' => $this->user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertNull($this->version());
    }

    /**
     * A real bearer token, not `actingAs`.
     *
     * `actingAs($user, 'sanctum')` authenticates with a TransientToken,
     * which has no row and so no column to write — the middleware skips
     * it deliberately, because that is also what a panel session is. A
     * phone always carries a real token, and only a real one exercises
     * this at all.
     */
    private function phone(string $name = 'گوشی'): string
    {
        return $this->user->createToken($name)->plainTextToken;
    }

    public function test_the_build_is_updated_when_the_phone_is_updated(): void
    {
        // Nobody signs in again after updating. A version recorded only at
        // sign-in would say 5.1.0 for as long as the token lived, whatever
        // was actually installed — wrong with the confidence of a fact.
        $token = $this->phone();
        $this->user->tokens()->latest('id')->first()
            ->forceFill(['app_version' => '5.1.0'])->save();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-App-Version' => '5.2.0',
        ])->getJson('/api/v1/me')->assertOk();

        $this->assertSame('5.2.0', $this->version());
    }

    public function test_the_list_shows_it(): void
    {
        $token = $this->phone('Samsung SM-A546E');

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-App-Version' => '5.2.0',
        ])->getJson('/api/v1/devices')
            ->assertOk()
            ->assertJsonPath('data.devices.0.app_version', '5.2.0');
    }

    /**
     * The panel signs in through the session guard, and Sanctum hands that
     * request a TransientToken — a plain object with no row behind it.
     *
     * `actingAs($user)` and not `actingAs($user, 'sanctum')`. The second
     * leaves the token null, so it takes the first branch and never meets
     * a transient one at all: written that way this test passed against a
     * middleware that fatally errored on every panel request, and forty-six
     * other tests caught what it was supposed to. A guard is only tested by
     * the thing it guards against.
     */
    public function test_a_panel_session_has_no_token_to_write_to(): void
    {
        $this->actingAs($this->user);

        $this->withHeader('X-App-Version', '5.2.0')
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    public function test_a_session_that_never_reported_reads_as_null(): void
    {
        $token = $this->phone('گوشی قدیمی');

        // An app old enough not to send the header. Null rather than a
        // guess: the screen says «نسخه نامشخص», which is the true answer.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/devices')
            ->assertOk()
            ->assertJsonPath('data.devices.0.app_version', null);
    }

    public function test_a_header_that_is_not_a_version_is_discarded(): void
    {
        // It is written into a column that is read back and shown to the
        // owner, and it arrives from the network.
        $token = $this->phone();

        foreach (['<script>alert(1)</script>', 'نسخه ۵', str_repeat('9', 40)] as $bad) {
            $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'X-App-Version' => $bad,
            ])->getJson('/api/v1/me')->assertOk();

            $this->assertNull($this->version(), "پذیرفته شد: {$bad}");
        }
    }

    public function test_a_bad_header_does_not_overwrite_a_good_one(): void
    {
        $token = $this->phone();
        $this->user->tokens()->latest('id')->first()
            ->forceFill(['app_version' => '5.2.0'])->save();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-App-Version' => 'نسخه ۵',
        ])->getJson('/api/v1/me')->assertOk();

        $this->assertSame('5.2.0', $this->version());
    }
}
