<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Signing the shop in to its own card terminal on nanino.
 *
 * The card reader is what the flour quota is measured against, and its
 * figures reached this system by somebody opening another website and
 * typing them in.
 *
 * This is the first half only — getting connected. Reading the history
 * comes after somebody has proved a session can be obtained, because
 * where the token sits in nanino's answer is an informed guess until
 * then, and building the rest on a guess means building it twice.
 */
class ConnectingToTheCardTerminalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        // Nothing in these tests touches nanino. It is somebody else's
        // service and a test suite has no business calling it.
        Http::preventStrayRequests();
    }

    public function test_a_fresh_shop_is_not_connected(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/nanino')
            ->assertOk()
            ->assertJsonPath('data.connected', false);
    }

    public function test_it_fetches_a_captcha_for_the_person_to_read(): void
    {
        Http::fake([
            '*/api/captcha' => Http::response([
                'encodedImage' => 'data:image/png;base64,AAAA',
                'accessKey' => 'key-123',
            ]),
        ]);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/nanino/captcha')
            ->assertOk()
            ->assertJsonPath('data.access_key', 'key-123')
            ->assertJsonPath('data.image', 'data:image/png;base64,AAAA');
    }

    public function test_the_captcha_the_person_typed_is_passed_through_untouched(): void
    {
        Http::fake(['*/api/otp/generate' => Http::response([])]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/nanino/code', [
                'mobile' => '09150000000',
                'national_number' => '0000000000',
                'access_key' => 'key-123',
                'captcha' => 'X7K2',
            ])
            ->assertOk();

        // A captcha exists to require a person. Nothing here solves one,
        // and nothing here should ever be changed to.
        Http::assertSent(fn ($request) => $request['captcha'] === 'X7K2'
            && $request['userType'] === 'MERCHANT');
    }

    public function test_a_wrong_code_does_not_record_a_connection(): void
    {
        Http::fake(['*/api/otp/validate' => Http::response([], 400)]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/nanino/connect', [
                'mobile' => '09150000000',
                'national_number' => '0000000000',
                'code' => '1111',
            ])
            ->assertStatus(502);

        $this->assertNull(Bakery::first()->nanino_token);
    }

    public function test_an_answer_with_no_token_is_refused_rather_than_recorded(): void
    {
        // The shape of nanino's answer is not promised to anyone. If it
        // changes, saying so now is better than a «connected» that fails
        // on its first read with something less legible.
        Http::fake(['*/api/otp/validate' => Http::response(['ok' => true])]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/nanino/connect', [
                'mobile' => '09150000000',
                'national_number' => '0000000000',
                'code' => '1234',
            ])
            ->assertStatus(502);

        $this->assertNull(Bakery::first()->nanino_token);
    }

    public function test_a_good_code_connects_and_remembers_who(): void
    {
        Http::fake([
            '*/api/otp/validate' => Http::response([
                'token' => 'a-session-token',
                'refreshToken' => 'a-refresh-token',
            ]),
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/nanino/connect', [
                'mobile' => '09150000000',
                'national_number' => '0000000000',
                'code' => '1234',
            ])
            ->assertOk();

        $bakery = Bakery::first();

        $this->assertSame('a-session-token', $bakery->nanino_token);
        // Kept so the owner is not typing his own national number into a
        // phone every time the session lapses.
        $this->assertSame('09150000000', $bakery->nanino_mobile);
        $this->assertNotNull($bakery->nanino_connected_at);
    }

    public function test_the_token_is_not_readable_straight_off_the_disk(): void
    {
        Http::fake([
            '*/api/otp/validate' => Http::response(['token' => 'a-session-token']),
        ]);

        $this->actingAs($this->admin)->postJson('/api/v1/nanino/connect', [
            'mobile' => '09150000000',
            'national_number' => '0000000000',
            'code' => '1234',
        ])->assertOk();

        $raw = DB::table('bakeries')
            ->where('id', Bakery::first()->id)
            ->value('nanino_token');

        $this->assertNotSame('a-session-token', $raw);
    }

    public function test_disconnecting_forgets_the_session(): void
    {
        Bakery::first()->forceFill(['nanino_token' => 'a-session-token'])->save();

        $this->actingAs($this->admin)
            ->deleteJson('/api/v1/nanino')
            ->assertOk();

        $this->assertNull(Bakery::first()->nanino_token);
    }

    public function test_staff_cannot_connect_or_read_the_link(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        // It is the owner's own account at the terminal company.
        $this->actingAs($seller)->getJson('/api/v1/nanino')->assertForbidden();
        $this->actingAs($seller)->getJson('/api/v1/nanino/captcha')->assertForbidden();
        $this->actingAs($seller)->postJson('/api/v1/nanino/connect', [
            'mobile' => '09150000000',
            'national_number' => '0000000000',
            'code' => '1234',
        ])->assertForbidden();
    }
}
