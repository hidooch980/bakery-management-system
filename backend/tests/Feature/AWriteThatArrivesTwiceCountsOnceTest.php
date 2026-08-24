<?php

namespace Tests\Feature;

use App\Http\Middleware\IdempotentWrites;
use App\Models\DoughEntry;
use App\Models\IdempotentRequest;
use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The duplicate sale that nothing could have told apart from a real one.
 *
 * The phone queues a write it thinks never arrived, and decides that from
 * the Dio exception type. Two of the types it counts as never-arrived —
 * receiveTimeout and sendTimeout — mean the request *did* reach the
 * server and very likely ran; only the answer was lost. Replaying one of
 * those recorded the sale again, and a second sale of the same bread at
 * the same minute by the same seller is indistinguishable from a real
 * one. Nobody would have found it except as a figure that would not
 * reconcile — which is how the 60 missing sacks were found, months late.
 *
 * These tests are about the retry being recognised, and about the three
 * ways that recognition could quietly do the wrong thing: serving one
 * request's answer to a different request, pinning a failure so a typo
 * cannot be corrected, and swallowing a real second sale.
 */
class AWriteThatArrivesTwiceCountsOnceTest extends TestCase
{
    use RefreshDatabase;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->baker = User::factory()->create(['is_active' => true]);
        $this->baker->assignRole('dough_maker');

        // Enough of everything the formula draws on that none of these
        // tests is accidentally a stock test.
        foreach ([InventoryItem::FLOUR, InventoryItem::SALT,
            InventoryItem::YEAST_DRY, InventoryItem::YEAST_WET] as $key) {
            InventoryItem::ofKey($key)->move('in', 5000, 'purchase');
        }
    }

    /**
     * A batch of dough. Chosen over a sale because a sale consumes a
     * chane entry and cannot honestly be made twice, and because a
     * duplicated batch moves flour out of the warehouse — which is the
     * shape of the 60 sacks that went missing before anyone noticed.
     *
     * `force` is set because the endpoint already guards against a person
     * recording the same batch twice by hand. That guard is about a human
     * pressing the button again; this one is about the network replaying
     * a request the human only made once, and the two must not be
     * confused for each other.
     */
    private function aBatch(): array
    {
        return ['bag_count' => 10, 'yeast_type' => 'dry', 'force' => true];
    }

    private function knead(array $body, ?string $key, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->baker, 'sanctum')
            ->withHeaders($key === null ? [] : ['Idempotency-Key' => $key])
            ->postJson('/api/v1/dough-entries', $body);
    }

    private function key(int $n): string
    {
        return sprintf('a1b2c3d4-e5f6-4a5b-8c9d-%012d', $n);
    }

    public function test_the_same_write_sent_twice_is_recorded_once(): void
    {
        $body = $this->aBatch();

        $this->knead($body, $this->key(1))->assertSuccessful();
        $this->knead($body, $this->key(1))->assertSuccessful();

        $this->assertSame(1, DoughEntry::count(), 'the replay recorded a second batch');
    }

    public function test_the_retry_is_given_the_first_answer_not_a_new_one(): void
    {
        $body = $this->aBatch();

        $first = $this->knead($body, $this->key(2));
        $again = $this->knead($body, $this->key(2));

        // The same id, not merely the same shape. The phone shows what it
        // is given, and two ids for one batch is how a shop ends up
        // chasing a record that does not exist.
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $again->assertHeader('Idempotent-Replay', 'true');
    }

    public function test_two_genuinely_separate_batches_both_land(): void
    {
        $this->knead($this->aBatch(), $this->key(3))->assertSuccessful();
        $this->knead($this->aBatch(), $this->key(4))->assertSuccessful();

        // Identical bodies, different names. A bakery really does knead
        // two identical batches in a row, and that must not be swallowed.
        $this->assertSame(2, DoughEntry::count());
    }

    public function test_a_name_reused_for_different_work_is_refused(): void
    {
        $this->knead($this->aBatch(), $this->key(5))->assertSuccessful();

        $different = $this->aBatch();
        $different['bag_count'] = 25;

        // Serving either answer would be a guess about which was meant.
        $this->knead($different, $this->key(5))->assertStatus(409);

        $this->assertSame(1, DoughEntry::count());
    }

    public function test_a_request_with_no_name_is_left_alone(): void
    {
        $this->knead($this->aBatch(), null)->assertSuccessful();
        $this->knead($this->aBatch(), null)->assertSuccessful();

        // Older app versions send no key. Refusing them would take the
        // shop offline the moment this deploys.
        $this->assertSame(2, DoughEntry::count());
        $this->assertSame(0, IdempotentRequest::count());
    }

    public function test_a_rejected_write_is_not_pinned(): void
    {
        $bad = $this->aBatch();
        $bad['bag_count'] = 0;

        $this->knead($bad, $this->key(6))->assertStatus(422);

        // A validation failure is not work that was done. Remembering it
        // would make the seller invent a fresh key to fix a typo, and he
        // has no way to do that.
        $this->assertSame(0, IdempotentRequest::count());

        $this->knead($this->aBatch(), $this->key(6))->assertSuccessful();
        $this->assertSame(1, DoughEntry::count());
    }

    public function test_a_malformed_name_is_refused_rather_than_trusted(): void
    {
        $this->knead($this->aBatch(), 'short')->assertStatus(400);
        $this->knead($this->aBatch(), str_repeat('x', 65))->assertStatus(400);

        $this->assertSame(0, DoughEntry::count());
    }

    public function test_the_fingerprint_does_not_care_about_key_order(): void
    {
        // An honest retry that serialised its json differently must still
        // look like the same request, or the 409 above fires on a phone
        // that did nothing wrong.
        $this->assertSame(
            IdempotentRequest::hashBody(['b' => 2, 'a' => ['y' => 1, 'x' => 2]]),
            IdempotentRequest::hashBody(['a' => ['x' => 2, 'y' => 1], 'b' => 2]),
        );

        $this->assertNotSame(
            IdempotentRequest::hashBody(['a' => 1]),
            IdempotentRequest::hashBody(['a' => 2]),
        );
    }

    public function test_old_names_are_pruned_and_recent_ones_are_kept(): void
    {
        $this->knead($this->aBatch(), $this->key(7));

        IdempotentRequest::query()->update(['created_at' => now()->subDays(10)]);

        $this->knead($this->aBatch(), $this->key(8));

        $this->artisan('idempotency:prune')->assertSuccessful();

        $this->assertSame(1, IdempotentRequest::count());
    }

    public function test_a_read_carrying_a_key_is_never_pinned(): void
    {
        // A read has nothing to make happen twice, and honouring a key on
        // one would freeze its answer for three days.
        $this->actingAs($this->baker, 'sanctum')
            ->withHeaders(['Idempotency-Key' => $this->key(10)])
            ->getJson('/api/v1/dough-entries/my-history')
            ->assertSuccessful();

        $this->assertSame(0, IdempotentRequest::count());
    }

    public function test_a_different_verb_is_not_given_the_other_verbs_answer(): void
    {
        $this->knead($this->aBatch(), $this->key(12))->assertSuccessful();

        $stored = IdempotentRequest::where('idempotency_key', $this->key(12))->first();

        // Method was recorded from the start and then never compared, so a
        // row written by a POST matched on path and body alone. A DELETE
        // could be handed the POST's answer and never run, telling the
        // caller a thing was created that was in fact never removed.
        $this->assertSame('POST', $stored->method);

        $response = $this->actingAs($this->baker, 'sanctum')
            ->withHeaders(['Idempotency-Key' => $this->key(12)])
            ->deleteJson('/api/v1/dough-entries', $this->aBatch());

        $response->assertHeaderMissing('Idempotent-Replay');
        $this->assertNotSame(201, $response->status());
    }

    public function test_the_shop_keeps_writing_when_the_migration_has_not_run(): void
    {
        // deploy.sh pulls the code and only *reports* pending migrations,
        // so there is a real window where this class is live and its table
        // is not. Unguarded, the lookup at the top throws before $next has
        // run and every write in the API answers 500 — no sale, no batch,
        // no collection. That is worse than the duplicate it prevents, and
        // it is the same failure a payslip hit on 1405/05/29.
        Schema::drop('idempotent_requests');
        IdempotentWrites::forgetTableCache();

        $this->knead($this->aBatch(), $this->key(13))->assertSuccessful();
        $this->knead($this->aBatch(), $this->key(14))->assertSuccessful();

        $this->assertSame(2, DoughEntry::count());
    }

    public function test_a_key_that_cannot_be_recorded_is_reported_not_swallowed(): void
    {
        // The write is committed by the time the key is saved, so a save
        // that fails for any reason other than the unique-index race
        // leaves that write unrecognisable and the next replay does it
        // again. The catch was written for the race and caught everything.
        Log::spy();

        Schema::table('idempotent_requests', function ($table) {
            $table->dropColumn('body_hash');
        });

        $this->knead($this->aBatch(), $this->key(15))->assertSuccessful();

        // The batch still lands. Answering a write that genuinely
        // succeeded with a 500 would have the phone queue it and send it
        // again — turning a logging problem into the duplicate itself.
        $this->assertSame(1, DoughEntry::count());

        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    public function test_one_persons_key_never_answers_with_another_persons_record(): void
    {
        $this->knead($this->aBatch(), $this->key(9))->assertSuccessful();

        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('dough_maker');

        // The same name under a different user is a client bug, not a
        // retry, and must never be answered with somebody else's record.
        $response = $this->knead($this->aBatch(), $this->key(9), $other);

        $response->assertStatus(409);
        $this->assertSame(1, DoughEntry::count());
    }
}
