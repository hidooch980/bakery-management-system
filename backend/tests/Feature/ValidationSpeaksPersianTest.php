<?php

namespace Tests\Feature;

use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * APP_LOCALE was fa from the beginning, but no lang directory existed, so
 * Laravel served its built-in English. It stayed hidden because every
 * validation failure was overwritten with one fixed Persian sentence on the
 * way out; the moment that stopped, staff started reading "The password
 * field is required" on a Persian screen.
 */
class ValidationSpeaksPersianTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
    }

    public function test_a_missing_field_is_reported_in_persian(): void
    {
        $message = $this->postJson('/api/v1/login', ['login' => '09120000000'])
            ->assertStatus(422)
            ->json('message');

        $this->assertStringNotContainsString('field is required', $message);
        $this->assertStringContainsString('الزامی است', $message);
    }

    public function test_the_field_is_named_in_persian_too(): void
    {
        // The raw column name means nothing to someone counting sacks.
        $message = $this->postJson('/api/v1/login', ['login' => '09120000000'])
            ->assertStatus(422)
            ->json('message');

        $this->assertStringContainsString('رمز عبور', $message);
        $this->assertStringNotContainsString('password', $message);
    }

    public function test_the_locale_is_actually_persian(): void
    {
        $this->assertSame('fa', config('app.locale'));
    }
}
