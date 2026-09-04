<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What happens when somebody says «گوشی‌ام گم شده».
 *
 * Until this existed, three things closed a session and all three were the
 * side effect of doing something else: changing a password, resetting one,
 * and an admin switching the account off. The last is what actually got
 * used, and it costs the person their job for the rest of the day — the
 * account is off, so they cannot record a sale on the shop's own handset
 * either. The other two need the password, which is the thing you cannot
 * be sure of once a signed-in phone is in a stranger's pocket.
 *
 * So: a list of the devices holding a session, and a way to close one.
 */
class ALostPhoneCanBeSignedOutTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->seller = User::factory()->create([
            'is_active' => true,
            'phone' => '09151234567',
            'password' => 'the-old-one-99',
        ]);
        $this->seller->assignRole('seller');
    }

    /** Signs in and returns the bearer token that session now holds. */
    private function signIn(?string $device): string
    {
        $response = $this->postJson('/api/v1/login', [
            'login' => '09151234567',
            'password' => 'the-old-one-99',
            ...$device === null ? [] : ['device_name' => $device],
        ])->assertOk();

        return $response->json('data.token');
    }

    /**
     * Speaks as the handset holding [$token].
     *
     * The guard is forgotten first. Every call in one test method runs
     * against the same container, so once a user has been resolved the
     * next request is answered from that and not from the token — which
     * makes a token deleted mid-test look like it still works. The real
     * server answers each request in a fresh process; this is the test
     * catching up with that, not a difference in behaviour.
     */
    private function as(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_a_session_is_named_by_the_handset_that_opened_it(): void
    {
        $token = $this->signIn('Xiaomi Redmi Note 12');

        $this->as($token)->getJson('/api/v1/devices')
            ->assertOk()
            ->assertJsonPath('data.devices.0.name', 'Xiaomi Redmi Note 12')
            ->assertJsonPath('data.devices.0.is_current', true);
    }

    public function test_an_older_build_that_sends_no_name_still_signs_in(): void
    {
        // The app on the shop's own handset updates when somebody
        // remembers to. A login that started refusing on a missing field
        // would take the till down on a Friday.
        $token = $this->signIn(null);

        $this->as($token)->getJson('/api/v1/devices')
            ->assertOk()
            ->assertJsonPath('data.devices.0.name', 'mobile-app');
    }

    public function test_the_phone_in_your_hand_is_marked_as_such(): void
    {
        $lost = $this->signIn('گوشی گم‌شده');
        $here = $this->signIn('گوشی مغازه');

        $devices = $this->as($here)->getJson('/api/v1/devices')
            ->assertOk()
            ->json('data.devices');

        $current = collect($devices)->firstWhere('is_current', true);

        // Without this the list is three identical rows and the person
        // closing one is guessing which of them they are holding.
        $this->assertSame('گوشی مغازه', $current['name']);
        $this->assertCount(2, $devices);
        $this->assertNotSame($lost, $here);
    }

    public function test_closing_one_device_leaves_the_others_working(): void
    {
        $lost = $this->signIn('گوشی گم‌شده');
        $here = $this->signIn('گوشی مغازه');

        $lostId = collect($this->as($here)->getJson('/api/v1/devices')->json('data.devices'))
            ->firstWhere('name', 'گوشی گم‌شده')['id'];

        $this->as($here)->deleteJson('/api/v1/devices/'.$lostId)->assertOk();

        // The lost handset is out — gone from the table, and refused.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $lostId]);
        $this->as($lost)->getJson('/api/v1/me')->assertUnauthorized();

        // And the person is still working, which is the whole point of
        // this existing rather than deactivating the account.
        $this->as($here)->getJson('/api/v1/me')->assertOk();
    }

    public function test_nobody_closes_somebody_elses_session(): void
    {
        $other = User::factory()->create([
            'is_active' => true,
            'phone' => '09159999999',
            'password' => 'the-old-one-99',
        ]);
        $other->assignRole('seller');

        $theirs = $other->createToken('گوشی همکار');
        $mine = $this->signIn('گوشی من');

        $this->as($mine)
            ->deleteJson('/api/v1/devices/'.$theirs->accessToken->id)
            ->assertNotFound();

        // A 404 and not a 403, and more to the point still there: an id
        // from another account must not be able to end a shift.
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $theirs->accessToken->id,
        ]);
    }

    public function test_everything_but_this_phone_can_go_at_once(): void
    {
        $this->signIn('گوشی گم‌شده');
        $this->signIn('گوشی قدیمی');
        $here = $this->signIn('گوشی مغازه');

        // Standing in the shop having just realised, nobody can say which
        // row the lost one is. This is the button they actually press.
        $this->as($here)->deleteJson('/api/v1/devices/others')
            ->assertOk()
            ->assertJsonPath('data.closed', 2);

        $this->as($here)->getJson('/api/v1/devices')
            ->assertOk()
            ->assertJsonCount(1, 'data.devices');
    }

    public function test_closing_the_phone_you_are_holding_says_so(): void
    {
        $here = $this->signIn('گوشی مغازه');

        $id = $this->as($here)->getJson('/api/v1/devices')->json('data.devices.0.id');

        // Allowed — it is just a logout — but the screen has to know, or it
        // sits there showing a list it can no longer read.
        $this->as($here)->deleteJson('/api/v1/devices/'.$id)
            ->assertOk()
            ->assertJsonPath('data.signed_self_out', true);

        $this->as($here)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_the_list_is_the_callers_own_and_nobody_elses(): void
    {
        $other = User::factory()->create([
            'is_active' => true,
            'phone' => '09159999999',
            'password' => 'the-old-one-99',
        ]);
        $other->assignRole('seller');
        $other->createToken('گوشی همکار');

        $mine = $this->signIn('گوشی من');

        $names = collect($this->as($mine)->getJson('/api/v1/devices')->json('data.devices'))
            ->pluck('name');

        $this->assertSame(['گوشی من'], $names->all());
    }

    public function test_a_signed_out_person_has_no_device_list(): void
    {
        $this->getJson('/api/v1/devices')->assertUnauthorized();
    }

    public function test_a_name_arrives_trimmed_rather_than_refused(): void
    {
        // This comes from device_info_plus, not from a form. A manufacturer
        // padding its model name should not cost somebody a login.
        $token = $this->signIn("  Samsung   Galaxy\nA54  ");

        $this->as($token)->getJson('/api/v1/devices')
            ->assertOk()
            ->assertJsonPath('data.devices.0.name', 'Samsung Galaxy A54');
    }
}
