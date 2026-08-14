<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What an unauthenticated caller gets.
 *
 * Laravel's default guest redirect asks for a route named `login`, which
 * this app has never had — the panel's is `filament.admin.auth.login`. The
 * redirect is resolved while the AuthenticationException is being built,
 * so it threw before the API's own 401 handler could run, and every
 * unauthenticated API request from anything that did not send
 * `Accept: application/json` came back a 500 error page.
 */
class UnauthenticatedRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_api_call_without_a_token_is_refused_not_broken(): void
    {
        // No JSON accept header — a browser, a probe, an older client.
        $this->get('/api/v1/bakery')->assertStatus(401);
    }

    public function test_an_api_call_asking_for_json_is_refused_the_same_way(): void
    {
        $this->getJson('/api/v1/bakery')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_the_refusal_says_so_in_the_shops_own_words(): void
    {
        $this->getJson('/api/v1/bakery')
            ->assertStatus(401)
            ->assertJsonPath('message', 'برای دسترسی باید وارد شوید.');
    }

    public function test_a_guest_at_the_panel_is_sent_to_its_login(): void
    {
        // The panel has a login page; a visitor should land on it rather
        // than on an error.
        $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_the_panel_login_page_itself_opens(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_the_public_page_needs_no_login(): void
    {
        $this->get('/')->assertOk();
    }
}
