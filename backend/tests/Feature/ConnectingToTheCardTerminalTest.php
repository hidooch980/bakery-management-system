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

    public function test_a_session_that_cannot_be_decrypted_reads_as_not_connected(): void
    {
        // A rotated APP_KEY, or a restore from a dump taken under a
        // different one. An `encrypted` cast throws rather than returning
        // null, so reading it straight would turn this into a 500 on the
        // settings screen instead of «you are not connected» — which is
        // both true and something the owner can act on.
        DB::table('bakeries')
            ->where('id', Bakery::first()->id)
            ->update(['nanino_token' => 'not-a-valid-payload']);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/nanino')
            ->assertOk()
            ->assertJsonPath('data.connected', false);
    }

    public function test_disconnecting_forgets_the_session(): void
    {
        Bakery::first()->forceFill(['nanino_token' => 'a-session-token'])->save();

        $this->actingAs($this->admin)
            ->deleteJson('/api/v1/nanino')
            ->assertOk();

        $this->assertNull(Bakery::first()->nanino_token);
    }

    public function test_a_number_typed_on_a_persian_keyboard_still_reaches_nanino(): void
    {
        // What the owner's phone actually produces. Every other number he
        // types goes through Sms::normalise(); this path did not, so the
        // figures were handed to nanino as «۰۹۱۵…» and refused — which is
        // «نانینو وصل نمی‌شه», with nothing on either side to show why.
        Http::fake(['*/api/otp/generate' => Http::response([])]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/nanino/code', [
                'mobile' => '۰۹۱۵۹۹۹۱۶۶۹',
                'national_number' => '۳۷۰۱۱۲۸۸۷۱',
                'access_key' => 'key-123',
                'captcha' => 'X7K2',
            ])
            ->assertOk();

        Http::assertSent(fn ($request) => $request['mobile'] === '09159991669'
            && $request['nationalNumber'] === '3701128871'
            // Still untouched. It is read off an image, not typed from
            // memory, and solving one is not this system's business.
            && $request['captcha'] === 'X7K2');
    }

    public function test_a_texted_code_typed_in_persian_figures_is_accepted(): void
    {
        Http::fake([
            '*/api/otp/validate' => Http::response(['token' => 'a-session-token']),
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/nanino/connect', [
                'mobile' => '۰۹۱۵۹۹۹۱۶۶۹',
                'national_number' => '۳۷۰۱۱۲۸۸۷۱',
                'code' => '۱۲۳۴۵۶',
            ])
            ->assertOk();

        Http::assertSent(fn ($request) => $request['otp'] === '123456');

        // And what is kept for the prefill is the Latin form, so the next
        // sign-in starts from something nanino accepts.
        $this->assertSame('09159991669', Bakery::first()->nanino_mobile);
    }

    public function test_a_number_that_is_not_a_mobile_is_refused_before_nanino_sees_it(): void
    {
        Http::preventStrayRequests();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/nanino/code', [
                'mobile' => '12345',
                'national_number' => '0000000000',
                'access_key' => 'key-123',
                'captcha' => 'X7K2',
            ])
            ->assertStatus(502)
            // The specific complaint, not just a refusal: without it the
            // owner is told «کد ارسال نشد» and left guessing which of the
            // four boxes he got wrong.
            ->assertJsonPath('message', 'شمارهٔ همراه درست نیست.');

        Http::assertNothingSent();
    }

    public function test_an_attempt_that_failed_leaves_a_trace(): void
    {
        // Before this, a shop that could not get in and a shop that had
        // never tried were indistinguishable on the record.
        Http::fake(['*/api/otp/validate' => Http::response([], 400)]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/nanino/connect', [
                'mobile' => '09150000000',
                'national_number' => '0000000000',
                'code' => '1111',
            ])
            ->assertStatus(502);

        $this->assertNotNull(Bakery::first()->nanino_last_error);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/nanino')
            ->assertOk()
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.last_error', 'کد وارد شده درست نبود.');
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
