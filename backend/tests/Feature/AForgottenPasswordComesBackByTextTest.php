<?php

namespace Tests\Feature;

use App\Models\PasswordResetCode;
use App\Models\User;
use App\Support\Sms;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Getting back in, by text.
 *
 * Nobody at this shop reads email. They have phones, and the way somebody
 * currently gets a forgotten password back is to find the owner and ask —
 * which means the owner has to be there, and means he learns a password
 * that is meant to be nobody's but theirs.
 *
 * Two things these tests are really about.
 *
 * **The endpoint must never say who is registered.** The staff use their
 * personal mobiles. An endpoint that answers differently for a known
 * number and an unknown one is a way to find out who works here, one
 * number at a time, and it takes no account and no password to use.
 *
 * **The code must be worth guessing at.** Six digits is a million
 * combinations, which is plenty — but only while the number of guesses is
 * bounded, the code expires, and it cannot be spent twice.
 */
class AForgottenPasswordComesBackByTextTest extends TestCase
{
    use RefreshDatabase;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->baker = User::factory()->create([
            'is_active' => true,
            'phone' => '09151234567',
            'password' => 'the-old-one-99',
        ]);
        $this->baker->assignRole('dough_maker');

        Sms::fake();
    }

    protected function tearDown(): void
    {
        Sms::stopFaking();

        parent::tearDown();
    }

    private function ask(string $phone = '09151234567'): TestResponse
    {
        return $this->postJson('/api/v1/forgot-password', ['phone' => $phone]);
    }

    /**
     * The code as it was texted, read off the captured message.
     *
     * The first version of this brute-forced it — hashing every six-digit
     * number until one matched the stored hash. That is a million bcrypt
     * comparisons, it wedged the suite for forty minutes, and it looked
     * exactly like a hang. Reading the message the shop actually sent is
     * both faster and closer to what a person does.
     */
    private function codeFor(string $phone = '09151234567'): string
    {
        $sent = collect(Sms::sent())->last(fn ($m) => $m['phone'] === $phone);

        $this->assertNotNull($sent, "هیچ پیامکی به {$phone} نرفت");

        preg_match('/(\d{6})/', Sms::latinDigits($sent['message']), $found);

        $this->assertNotEmpty($found, 'کدی در متن پیامک نبود');

        return $found[1];
    }

    public function test_asking_creates_a_code(): void
    {
        $this->ask()->assertOk();

        $this->assertSame(1, PasswordResetCode::count());
        $this->assertSame($this->baker->id, PasswordResetCode::first()->user_id);
    }

    public function test_the_code_is_not_stored_in_the_clear(): void
    {
        $this->ask();

        $row = PasswordResetCode::first();

        // A reset table in plain text is a list of live keys to every
        // account in the shop, and a database is read by more people than
        // a password ever is.
        $this->assertNotSame($this->codeFor(), $row->code_hash);
        $this->assertTrue(strlen($row->code_hash) > 20);
    }

    public function test_an_unknown_number_gets_the_same_answer(): void
    {
        $known = $this->ask()->assertOk()->json();
        $unknown = $this->ask('09999999999')->assertOk()->json();

        // Word for word. Anything that differs — the message, the status,
        // even how long it takes to a careful eye — turns this into a way
        // of finding out who works here.
        $this->assertSame($known['message'], $unknown['message']);
        $this->assertSame(1, PasswordResetCode::count());
    }

    public function test_a_number_typed_any_of_the_usual_ways_is_the_same_number(): void
    {
        foreach (['09151234567', '+989151234567', '00989151234567', '۰۹۱۵۱۲۳۴۵۶۷', '0915 123 4567'] as $written) {
            $this->assertSame('09151234567', Sms::normalise($written), $written);
        }
    }

    public function test_nonsense_is_not_a_number(): void
    {
        $this->assertNull(Sms::normalise('سلام'));
        $this->assertNull(Sms::normalise('021334455'));
        $this->assertNull(Sms::normalise(''));
    }

    public function test_the_code_lets_the_password_be_changed(): void
    {
        $this->ask();

        $this->postJson('/api/v1/reset-password', [
            'phone' => '09151234567',
            'code' => $this->codeFor(),
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-one', $this->baker->fresh()->password));
    }

    public function test_the_new_password_works_at_the_login(): void
    {
        $this->ask();

        $this->postJson('/api/v1/reset-password', [
            'phone' => '09151234567',
            'code' => $this->codeFor(),
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ])->assertOk();

        // The whole point, and the one thing a passing reset does not
        // prove on its own.
        // The endpoint takes «login», which is matched against either the
        // phone or the email — one box on the screen, two things behind it.
        $this->postJson('/api/v1/login', [
            'login' => '09151234567',
            'password' => 'a-brand-new-one',
        ])->assertOk();
    }

    public function test_a_wrong_code_is_counted(): void
    {
        $this->ask();

        $this->postJson('/api/v1/reset-password', [
            'phone' => '09151234567',
            'code' => '000000',
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ])->assertStatus(422);

        $this->assertSame(1, PasswordResetCode::first()->attempts);
    }

    public function test_the_code_burns_after_enough_wrong_guesses(): void
    {
        $this->ask();
        $real = $this->codeFor();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/reset-password', [
                'phone' => '09151234567',
                'code' => str_pad((string) $i, 6, '9'),
                'password' => 'a-brand-new-one',
                'password_confirmation' => 'a-brand-new-one',
            ]);
        }

        // Even the right code is no good now. Six digits is a million
        // combinations only while the guessing is bounded.
        $this->postJson('/api/v1/reset-password', [
            'phone' => '09151234567',
            'code' => $real,
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ])->assertStatus(422);
    }

    public function test_a_code_cannot_be_spent_twice(): void
    {
        $this->ask();
        $code = $this->codeFor();

        $body = [
            'phone' => '09151234567',
            'code' => $code,
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ];

        $this->postJson('/api/v1/reset-password', $body)->assertOk();
        $this->postJson('/api/v1/reset-password', $body)->assertStatus(422);
    }

    public function test_an_expired_code_is_no_good(): void
    {
        $this->ask();
        $code = $this->codeFor();

        PasswordResetCode::first()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/reset-password', [
            'phone' => '09151234567',
            'code' => $code,
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ])->assertStatus(422);
    }

    public function test_asking_again_kills_the_first_code(): void
    {
        $this->ask();
        $first = $this->codeFor();

        $this->ask();

        // Two live codes double the guessing surface for no benefit —
        // somebody who asked twice is reading the newest message.
        $this->postJson('/api/v1/reset-password', [
            'phone' => '09151234567',
            'code' => $first,
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ])->assertStatus(422);
    }

    public function test_the_shop_is_not_made_to_pay_for_a_thousand_messages(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->ask()->assertOk();
        }

        // Each message costs money and rings somebody's phone. Counted per
        // number rather than per caller, because changing address is easier
        // than changing which number you are pestering.
        $this->assertSame(3, PasswordResetCode::count());
    }

    public function test_someone_switched_off_cannot_reset(): void
    {
        $this->baker->update(['is_active' => false]);

        $this->ask()->assertOk();

        $this->assertSame(0, PasswordResetCode::count());
    }

    public function test_a_reset_signs_every_device_out(): void
    {
        $this->baker->createToken('mobile-app');
        $this->assertSame(1, $this->baker->tokens()->count());

        $this->ask();

        $this->postJson('/api/v1/reset-password', [
            'phone' => '09151234567',
            'code' => $this->codeFor(),
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ])->assertOk();

        // If somebody else knew the old password, this is the moment they
        // stop being able to use it.
        $this->assertSame(0, $this->baker->tokens()->count());
    }

    public function test_a_guessable_new_password_is_refused(): void
    {
        $this->ask();

        $this->postJson('/api/v1/reset-password', [
            'phone' => '09151234567',
            'code' => $this->codeFor(),
            'password' => '12345678',
            'password_confirmation' => '12345678',
        ])->assertStatus(422);
    }

    public function test_the_password_must_be_confirmed(): void
    {
        $this->ask();

        // A password typed once and mistyped locks the person out of the
        // account they were trying to get back into.
        $this->postJson('/api/v1/reset-password', [
            'phone' => '09151234567',
            'code' => $this->codeFor(),
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-two',
        ])->assertStatus(422);
    }
}
