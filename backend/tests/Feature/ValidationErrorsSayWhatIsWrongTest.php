<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The app shows `message` and nothing else. Every validation failure used to
 * be flattened into one fixed sentence about invalid data, so a seller who
 * mistyped their password was told the app had sent something wrong — which
 * reads as a broken app, and got reported as one.
 */
class ValidationErrorsSayWhatIsWrongTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
    }

    public function test_a_wrong_password_says_so(): void
    {
        $user = User::factory()->create([
            'phone' => '09120000000',
            'password' => Hash::make('correct-horse'),
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/login', [
            'login' => $user->phone,
            'password' => 'not-the-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'اطلاعات ورود نادرست است.');
    }

    public function test_an_unknown_login_says_so_too(): void
    {
        $this->postJson('/api/v1/login', [
            'login' => '09129999999',
            'password' => 'anything',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'اطلاعات ورود نادرست است.');
    }

    public function test_a_missing_field_names_the_field(): void
    {
        $response = $this->postJson('/api/v1/login', ['login' => '09120000000']);

        $response->assertStatus(422);

        // Whatever the rule says, it must not be the old fixed sentence.
        $this->assertNotSame(
            'اطلاعات ارسالی نامعتبر است.',
            $response->json('message'),
        );
        $this->assertArrayHasKey('password', $response->json('errors'));
    }

    public function test_the_field_errors_are_still_there_for_forms(): void
    {
        // The per-field map has to survive: a form highlighting the box that
        // is wrong needs it, and only `message` was ever the problem.
        $this->postJson('/api/v1/login', [])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['login', 'password']]);
    }
}
