<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which Android each member of staff is connected on.
 *
 * The device list has named the phone and the build for a while, and
 * that turned out to be the two thirds of the question that do not
 * answer it. A seller reported the new APK would not install; the list
 * said the handset's model and an app version three releases old, and
 * neither said the thing that mattered — the phone was on an Android
 * older than the app has required since its seventh change, so no
 * release since could ever have installed on it.
 *
 * Nobody could have known that from any screen. That is what this is
 * for.
 */
class EachHandsetSaysWhichAndroidItIsOnTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    /**
     * A real token, not `actingAs`.
     *
     * `actingAs(…, 'sanctum')` hands the request a TransientToken, which
     * has no row to write a version onto and never appears in the device
     * list — so a test built on it would pass against a middleware that
     * does nothing at all.
     */
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        $this->token = $this->seller->createToken('Samsung SM-J250F')->plainTextToken;
    }

    public function test_the_handset_reports_its_android_and_the_list_shows_it(): void
    {
        $this->withToken($this->token)
            ->withHeaders([
                'X-Device-OS' => 'Android 13',
                'X-Device-SDK' => '33',
            ])
            ->getJson('/api/v1/devices')
            ->assertOk();

        $row = $this->devices()[0];

        $this->assertSame('Android 13', $row['os_version']);
        $this->assertSame(33, $row['sdk_int']);
    }

    public function test_a_phone_too_old_for_a_release_is_named_as_such(): void
    {
        // Android 6.0. The app has needed 24 since its seventh change,
        // so nothing released since can be installed here.
        $this->report(os: 'Android 6.0', sdk: 23);

        $row = $this->devices()[0];

        $this->assertFalse($row['can_install_updates']);
    }

    public function test_a_phone_that_can_take_a_release_is_not_warned_about(): void
    {
        $this->report(os: 'Android 7.0', sdk: 24);

        // Exactly at the floor: the boundary is the case somebody would
        // get wrong, and it is the one a seller's phone sits on.
        $this->assertTrue($this->devices()[0]['can_install_updates']);
    }

    /**
     * A handset that has never reported its level.
     *
     * «Cannot take updates» would send somebody to replace a phone that
     * is perfectly fine, so not-known is its own answer rather than a
     * false one.
     */
    public function test_an_unreported_phone_is_not_called_stranded(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/devices')
            ->assertOk();

        $row = $this->devices()[0];

        $this->assertNull($row['os_version']);
        $this->assertNull($row['can_install_updates']);
    }

    public function test_the_version_is_kept_up_to_date_rather_than_frozen_at_sign_in(): void
    {
        // Nobody signs in again after a system update; the token
        // outlives it. A field that says Android 9 while the phone runs
        // 10 is worse than an empty one — it is wrong with the
        // confidence of a fact.
        $this->report(os: 'Android 9', sdk: 28);
        $this->report(os: 'Android 10', sdk: 29);

        $row = $this->devices()[0];

        $this->assertSame('Android 10', $row['os_version']);
        $this->assertSame(29, $row['sdk_int']);
    }

    public function test_a_made_up_header_is_dropped_rather_than_stored(): void
    {
        $this->report(os: 'Android 13', sdk: 33);

        // It is written into a column the owner reads back, and it
        // arrives from the network.
        $this->report(os: '<script>alert(1)</script>', sdk: 999999);

        $row = $this->devices()[0];

        $this->assertSame('Android 13', $row['os_version']);
        $this->assertSame(33, $row['sdk_int']);
    }

    public function test_reporting_never_refuses_a_request(): void
    {
        // A phone that cannot report its version still sells bread.
        $this->withToken($this->token)
            ->withHeaders(['X-Device-OS' => str_repeat('x', 500)])
            ->getJson('/api/v1/devices')
            ->assertOk();
    }

    // ---------------------------------------------------------- helpers

    private function report(string $os, int $sdk): void
    {
        $this->withToken($this->token)
            ->withHeaders(['X-Device-OS' => $os, 'X-Device-SDK' => (string) $sdk])
            ->getJson('/api/v1/devices')
            ->assertOk();
    }

    private function devices(): array
    {
        return $this->withToken($this->token)
            ->getJson('/api/v1/devices')
            ->assertOk()
            ->json('data.devices');
    }
}
