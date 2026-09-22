<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BakeryApplication;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentBakery;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Selling the system needs a way in for people who are not yet in it.
 *
 * That way in is an unauthenticated door on a server that runs a working
 * bakery — which is the whole reason it is an *application* and not a
 * signup. Nothing a stranger sends creates a shop, creates a login, or
 * grants anything at all. It creates a row that says somebody asked.
 */
class APublicDoorThatOnlyTakesAMessageTest extends TestCase
{
    use RefreshDatabase;

    private Bakery $head;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        RateLimiter::clear('');

        $this->head = Bakery::query()->oldest('id')->first();

        $this->owner = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $this->head->id,
        ]);
        $this->owner->assignRole('admin');

        CurrentBakery::forget();
    }

    public function test_anybody_can_ask(): void
    {
        $this->postJson('/api/v1/bakery-applications', [
            'bakery_name' => 'نانوایی سنگکی مهر',
            'owner_name' => 'حسن مرادی',
            'phone' => '09120000000',
            'city' => 'زاهدان',
        ])->assertCreated();

        $this->assertSame(1, BakeryApplication::count());
    }

    /**
     * The whole point of the design.
     *
     * A public endpoint that could open a shop would be a public
     * endpoint that opens shops.
     */
    public function test_asking_creates_no_shop_and_no_login(): void
    {
        $shopsBefore = Bakery::count();
        $usersBefore = User::count();

        $this->postJson('/api/v1/bakery-applications', [
            'bakery_name' => 'نانوایی تازه',
            'owner_name' => 'کسی',
            'phone' => '09120000001',
        ])->assertCreated();

        $this->assertSame($shopsBefore, Bakery::count());
        $this->assertSame($usersBefore, User::count());
        $this->assertSame(0, Subscription::count());
    }

    public function test_pressing_the_button_twice_is_not_two_bakeries(): void
    {
        $body = [
            'bakery_name' => 'نانوایی تازه',
            'owner_name' => 'کسی',
            'phone' => '09120000002',
        ];

        $this->postJson('/api/v1/bakery-applications', $body)->assertCreated();
        $this->postJson('/api/v1/bakery-applications', $body)->assertOk();

        $this->assertSame(1, BakeryApplication::count());
    }

    public function test_a_stranger_cannot_read_who_has_asked(): void
    {
        $this->getJson('/api/v1/bakery-applications')->assertUnauthorized();
    }

    public function test_approving_opens_the_shop_with_a_subscription(): void
    {
        $application = $this->applied();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bakery-applications/{$application->id}/approve", [
                'password' => 'A-long-enough-one-42',
                'email' => 'hassan@example.com',
                'months' => 12,
            ])
            ->assertOk();

        $application = $application->fresh();

        $this->assertSame(BakeryApplication::APPROVED, $application->status);
        $this->assertNotNull($application->bakery_id);

        $term = Subscription::currentFor($application->bakery_id);
        $this->assertNotNull($term);
        $this->assertTrue($term->is_current);
    }

    public function test_the_new_shops_admin_can_sign_in(): void
    {
        $application = $this->applied(email: 'hassan@example.com');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bakery-applications/{$application->id}/approve", [
                'password' => 'A-long-enough-one-42',
            ])
            ->assertOk();

        // A shop whose owner cannot get in is not a shop that was opened.
        $this->postJson('/api/v1/login', [
            'login' => 'hassan@example.com',
            'password' => 'A-long-enough-one-42',
        ])->assertOk();
    }

    public function test_the_same_application_is_not_approved_twice(): void
    {
        $application = $this->applied();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bakery-applications/{$application->id}/approve", [
                'password' => 'A-long-enough-one-42',
                'email' => 'hassan@example.com',
            ])
            ->assertOk();

        // The second shop would be a ghost nobody ever signs in to.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bakery-applications/{$application->id}/approve", [
                'password' => 'Another-long-one-42',
                'email' => 'second@example.com',
            ])
            ->assertStatus(409);

        $this->assertSame(2, Bakery::count());
    }

    /**
     * An admin of a shop that was itself opened this way.
     *
     * They hold the same permission their own shop gives them, which
     * would otherwise be enough to open more shops on somebody else's
     * system.
     */
    public function test_an_admin_of_another_shop_cannot_open_shops(): void
    {
        $other = Bakery::create(['name' => 'نانوایی دیگر']);

        $guest = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $other->id,
        ]);
        $guest->assignRole('admin');

        $application = $this->applied();

        $this->actingAs($guest, 'sanctum')
            ->getJson('/api/v1/bakery-applications')
            ->assertForbidden();

        $this->actingAs($guest, 'sanctum')
            ->postJson("/api/v1/bakery-applications/{$application->id}/approve", [
                'password' => 'A-long-enough-one-42',
                'email' => 'hassan@example.com',
            ])
            ->assertForbidden();

        $this->assertSame(BakeryApplication::PENDING, $application->fresh()->status);
    }

    public function test_a_rejection_keeps_its_reason(): void
    {
        $application = $this->applied();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bakery-applications/{$application->id}/reject", [
                'reason' => 'شمارهٔ تماس جواب نمی‌دهد',
            ])
            ->assertOk();

        $application = $application->fresh();

        $this->assertSame(BakeryApplication::REJECTED, $application->status);
        $this->assertSame('شمارهٔ تماس جواب نمی‌دهد', $application->rejection_reason);
        $this->assertSame($this->owner->id, $application->reviewed_by);
    }

    public function test_a_rejection_without_a_reason_is_refused(): void
    {
        $application = $this->applied();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bakery-applications/{$application->id}/reject", [])
            ->assertStatus(422);
    }

    public function test_a_guessable_password_does_not_open_a_shop(): void
    {
        $application = $this->applied();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bakery-applications/{$application->id}/approve", [
                'password' => 'password',
                'email' => 'hassan@example.com',
            ])
            ->assertStatus(422);

        $this->assertSame(BakeryApplication::PENDING, $application->fresh()->status);
    }

    /**
     * An applicant only has to give a phone.
     *
     * `users.email` is not nullable, so a phone-only application used to
     * crash the approval on the database constraint. The owner supplies
     * one when they approve — they are on the phone to the person
     * anyway — rather than the door demanding it of a village baker.
     */
    public function test_a_phone_only_application_needs_an_email_at_approval(): void
    {
        $application = $this->applied();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bakery-applications/{$application->id}/approve", [
                'password' => 'A-long-enough-one-42',
            ])
            ->assertStatus(422);

        $this->assertSame(BakeryApplication::PENDING, $application->fresh()->status);
    }

    public function test_an_email_already_in_use_does_not_open_a_shop(): void
    {
        $application = $this->applied();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bakery-applications/{$application->id}/approve", [
                'password' => 'A-long-enough-one-42',
                'email' => $this->owner->email,
            ])
            ->assertStatus(422);

        $this->assertSame(BakeryApplication::PENDING, $application->fresh()->status);
    }

    // ---------------------------------------------------------- helpers

    private function applied(?string $email = null): BakeryApplication
    {
        return BakeryApplication::create([
            'bakery_name' => 'نانوایی سنگکی مهر',
            'owner_name' => 'حسن مرادی',
            'phone' => '09120000009',
            'email' => $email,
            'status' => BakeryApplication::PENDING,
        ]);
    }
}
