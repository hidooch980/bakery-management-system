<?php

namespace Tests\Feature;

use App\Models\User;
use App\Rules\NotAGuessablePassword;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Eight characters was never the test.
 *
 * Every place in this system already required eight, and on 2026-08-18 one
 * of the shop's five accounts still had a password from the top of every
 * published list. Eight characters of «12345678» is eight characters, and
 * it is guessed in under a second by anyone who knows the phone number —
 * which for staff on their personal mobiles is not a secret.
 *
 * So the rule is not «how long» but «would somebody try this».
 */
class AGuessablePasswordIsRefusedTest extends TestCase
{
    use RefreshDatabase;

    private function passes(string $password): bool
    {
        return Validator::make(
            ['password' => $password],
            ['password' => [new NotAGuessablePassword]],
        )->passes();
    }

    public function test_the_commonest_passwords_are_refused(): void
    {
        foreach (['12345678', 'password', 'admin123', 'qwertyui', 'iloveyou'] as $password) {
            $this->assertFalse($this->passes($password), $password.' should be refused');
        }
    }

    public function test_case_does_not_help(): void
    {
        $this->assertFalse($this->passes('Password'));
        $this->assertFalse($this->passes('PASSWORD'));
    }

    public function test_persian_digits_do_not_help(): void
    {
        // «۱۲۳۴۵۶۷۸» is the same password to everyone except a string
        // comparison, and it is what gets typed on a Persian keyboard.
        $this->assertFalse($this->passes('۱۲۳۴۵۶۷۸'));
    }

    public function test_one_character_over_and_over_is_refused(): void
    {
        $this->assertFalse($this->passes('aaaaaaaa'));
        $this->assertFalse($this->passes('11111111'));
        $this->assertFalse($this->passes('........'));
    }

    public function test_a_straight_run_of_digits_is_refused(): void
    {
        // Not on any published list and tried by every tool.
        $this->assertFalse($this->passes('23456789'));
        $this->assertFalse($this->passes('98765432'));
    }

    public function test_the_shops_own_words_are_refused(): void
    {
        // The first thing anybody who knows the shop would type.
        $this->assertFalse($this->passes('mollazehi'));
        $this->assertFalse($this->passes('bakery123'));
    }

    public function test_an_ordinary_password_passes(): void
    {
        foreach (['naan-e-sangak-42', 'Kh4bazi!Mollazehi', 'tanoor-garm-1405'] as $password) {
            $this->assertTrue($this->passes($password), $password.' should pass');
        }
    }

    public function test_digits_that_are_not_a_run_pass(): void
    {
        // The rule is about runs and repeats, not about digits. A PIN-like
        // password that is genuinely arbitrary is not what this catches.
        $this->assertTrue($this->passes('40719238'));
    }

    public function test_the_api_refuses_a_guessable_password_on_a_new_user(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole('admin');

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/users', [
            'name' => 'کارمند تازه',
            'phone' => '09151112233',
            'password' => '12345678',
            'role' => 'dough_maker',
        ])->assertStatus(422);
    }

    public function test_the_api_refuses_it_when_changing_your_own(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $baker = User::factory()->create([
            'is_active' => true,
            'password' => 'the-old-one-99',
        ]);
        $baker->assignRole('dough_maker');

        Sanctum::actingAs($baker);

        $this->postJson('/api/v1/change-password', [
            'current_password' => 'the-old-one-99',
            'new_password' => 'password',
            'new_password_confirmation' => 'password',
        ])->assertStatus(422);
    }
}
