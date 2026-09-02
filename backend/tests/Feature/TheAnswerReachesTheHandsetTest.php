<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Loan;
use App\Models\User;
use App\Support\TodayAnswer;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The phone gets the same answer as the panel, in the same words.
 *
 * The owner is more often beside the oven than at a desk. An answer he can
 * only get by sitting down is one he goes on getting by asking a person
 * instead — which is how a 400 kg hole in the ledger survived four days of
 * screens that all said green.
 *
 * Every sentence is composed on the server in `TodayAnswer`, so the two
 * surfaces cannot come to different conclusions about the same shop. That
 * is what most of this file is about: not that the endpoint answers, but
 * that it answers the *same thing*.
 */
class TheAnswerReachesTheHandsetTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['flour_bag_weight_kg' => 40]);

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');

        @mkdir(storage_path('app/backups'), 0775, true);
        file_put_contents(storage_path('app/backups/handset-test.sql.gz'), 'x');
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/backups/handset-test.sql.gz'));

        parent::tearDown();
    }

    private function ask()
    {
        return $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/today');
    }

    public function test_it_answers_in_a_sentence(): void
    {
        // Nothing is waiting on this shop, so the tone is «clear» rather
        // than «sound» — the difference is what the phone paints, and a
        // test that accepted either would not be saying anything.
        $this->ask()
            ->assertOk()
            ->assertJsonPath('data.system', 'مغازه امروز سالم است.')
            ->assertJsonPath('data.yours', 'هیچ چیز کار شما نیست.')
            ->assertJsonPath('data.sound', true)
            ->assertJsonPath('data.tone', 'clear');
    }

    /**
     * Sent rather than written into the app, so adding a cycle does not
     * need a release to stop the phone claiming the old number.
     */
    public function test_the_cycle_count_travels_with_the_answer(): void
    {
        $this->ask()->assertJsonPath('data.cycles', 8);
    }

    public function test_what_is_waiting_comes_with_what_to_do_about_it(): void
    {
        Loan::create([
            'title' => 'وام صادرات',
            'principal' => 40_000_000,
            'instalment_amount' => 4_000_000,
            'instalment_count' => 10,
            'first_due_on' => now()->subDays(2),
        ]);

        $response = $this->ask()->assertOk();

        $needs = collect($response->json('data.needs'));
        $loan = $needs->firstWhere('severity', 'critical');

        $this->assertNotNull($loan, 'the overdue instalment should reach the phone');
        $this->assertStringContainsString('وام صادرات', $loan['title']);
        $this->assertNotSame('', $loan['suggestion'], 'a phone has nowhere to put a click-through');
    }

    /**
     * The load-bearing distinction, asserted from the phone's side too: a
     * sound system and a busy shop are different statements.
     */
    public function test_a_busy_shop_is_still_a_sound_one(): void
    {
        Loan::create([
            'title' => 'وام صادرات',
            'principal' => 40_000_000,
            'instalment_amount' => 4_000_000,
            'instalment_count' => 10,
            'first_due_on' => now()->subDays(2),
        ]);

        $this->ask()
            ->assertJsonPath('data.sound', true)
            ->assertJsonPath('data.tone', 'sound')
            ->assertJsonPath('data.system', 'مغازه امروز سالم است.');
    }

    public function test_a_contradiction_says_the_figures_cannot_be_trusted(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->movements()->create([
            'direction' => 'out',
            'quantity' => 500,
            'reason' => 'production',
        ]);

        $this->ask()
            ->assertJsonPath('data.sound', false)
            ->assertJsonPath('data.tone', 'fail')
            ->assertJsonPath('data.yours', 'تا این درست نشود به عددهای پایین اعتماد نکنید.')
            ->assertJsonCount(1, 'data.failures');
    }

    public function test_the_figures_arrive_ready_to_draw(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 2606, 'purchase');

        $figures = collect($this->ask()->json('data.figures'));

        $this->assertSame('۶۵٫۲ کیسه', $figures->firstWhere('label', 'آرد')['value']);
    }

    /**
     * The whole point of the class: one composer, two surfaces. If the
     * endpoint ever starts phrasing its own sentence this fails, which is
     * the moment the two would begin to drift.
     */
    public function test_the_phone_and_the_panel_are_given_the_same_words(): void
    {
        Customer::create([
            'name' => 'منصور پرکی نانوایی ناهوت ',
            'type' => 'school',
            'is_active' => true,
        ]);

        $onTheDesk = TodayAnswer::now();
        $inTheHand = $this->ask()->json('data');

        $this->assertSame($onTheDesk->sentence()['system'], $inTheHand['system']);
        $this->assertSame($onTheDesk->sentence()['yours'], $inTheHand['yours']);
        $this->assertSame($onTheDesk->cycleCount(), $inTheHand['cycles']);
        $this->assertSame($onTheDesk->figures(), $inTheHand['figures']);
        $this->assertSame($onTheDesk->health->warnings(), $inTheHand['warnings']);
    }

    /** It reads the whole shop's books, so it is behind the same door. */
    public function test_a_seller_cannot_read_it(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/today')->assertForbidden();
    }

    public function test_a_stranger_cannot_read_it(): void
    {
        $this->getJson('/api/v1/today')->assertUnauthorized();
    }
}
