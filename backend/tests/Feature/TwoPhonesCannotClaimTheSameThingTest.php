<?php

namespace Tests\Feature;

use App\Exceptions\AlreadyClaimedException;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\Sale;
use App\Models\SettlementRequest;
use App\Models\User;
use App\Support\Exclusively;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The batch two sellers could both sell.
 *
 * Every "has this already happened?" guard in this app was a read, then a
 * decision, then a write, with nothing holding the row in between. Two
 * phones a moment apart both read `pending`, both passed the check, and
 * both recorded: bread out of the warehouse twice and the money charged
 * against the seller twice, with every guard doing exactly what it was
 * written to do.
 *
 * The idempotency key does not reach this. That catches one phone sending
 * the same write twice; this is two people making two genuinely different
 * requests, carrying different keys because they are different.
 *
 * A race cannot be reproduced by two sequential requests in a test — the
 * first one finishes before the second starts, so the plain guard would
 * pass too and prove nothing. What is testable, and what actually matters,
 * is that the decision is made against the row as it stands *inside* the
 * lock rather than a copy read before it: the tests below change the row
 * from underneath and check which copy the guard believed.
 */
class TwoPhonesCannotClaimTheSameThingTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'rial', 'bread_price' => 5000]);
        Money::forgetCache();

        foreach ([InventoryItem::FLOUR, InventoryItem::SALT,
            InventoryItem::YEAST_DRY] as $key) {
            InventoryItem::ofKey($key)->move('in', 5000, 'purchase');
        }

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');
    }

    private function aBatch(int $count = 100): ChaneEntry
    {
        $dough = DoughEntry::create([
            'user_id' => $this->seller->id,
            'bag_count' => 1,
        ]);

        return ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => $count,
            'normal_weight_kg' => $count * 0.85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
        ]);
    }

    // ------------------------------------------------- the helper itself

    public function test_the_guard_reads_the_row_as_it_stands_not_as_it_was(): void
    {
        $batch = $this->aBatch();

        // Somebody else got there between this copy being fetched and the
        // claim being made. That is the whole race, in one line.
        ChaneEntry::whereKey($batch->id)->update(['status' => 'sold']);

        $this->expectException(AlreadyClaimedException::class);

        Exclusively::claim(
            $batch,
            fn (ChaneEntry $c) => $c->status === 'pending' ? null : 'قبلاً فروخته شده',
            fn () => 'should never run',
        );
    }

    public function test_the_work_is_handed_the_fresh_copy_too(): void
    {
        $batch = $this->aBatch(100);

        ChaneEntry::whereKey($batch->id)->update(['chane_count' => 250]);

        // Not just the guard. Work that acted on the stale copy would
        // write figures derived from a count nobody has any more.
        $seen = Exclusively::claim(
            $batch,
            fn () => null,
            fn (ChaneEntry $c) => $c->chane_count,
        );

        $this->assertSame(250, $seen);
    }

    public function test_a_row_that_has_gone_is_said_plainly(): void
    {
        $batch = $this->aBatch();

        ChaneEntry::whereKey($batch->id)->delete();

        $this->expectException(AlreadyClaimedException::class);

        Exclusively::claim($batch, fn () => null, fn () => 'should never run');
    }

    public function test_nothing_is_written_when_the_claim_is_refused(): void
    {
        $batch = $this->aBatch();

        ChaneEntry::whereKey($batch->id)->update(['status' => 'sold']);

        try {
            Exclusively::claim(
                $batch,
                fn (ChaneEntry $c) => $c->status === 'pending' ? null : 'قبلاً',
                function (ChaneEntry $c) {
                    Sale::create([
                        'chane_entry_id' => $c->id,
                        'user_id' => $this->seller->id,
                        'payment_type' => 'cash',
                        'bread_count' => 10,
                        'amount' => 50_000,
                    ]);

                    return null;
                },
            );
        } catch (AlreadyClaimedException) {
            // expected
        }

        // The refusal happens inside the transaction, so a half-done claim
        // leaves nothing behind.
        $this->assertSame(0, Sale::count());
    }

    public function test_work_that_throws_leaves_the_row_alone(): void
    {
        $batch = $this->aBatch();

        try {
            Exclusively::claim(
                $batch,
                fn () => null,
                function (ChaneEntry $c) {
                    $c->update(['status' => 'sold']);

                    throw new \RuntimeException('something went wrong');
                },
            );
        } catch (\RuntimeException) {
            // expected
        }

        // Rolled back with everything else. A batch marked sold by a claim
        // that then failed is a batch nobody can ever sell.
        $this->assertSame('pending', $batch->fresh()->status);
    }

    // ------------------------------------------------- the sale endpoint

    public function test_a_batch_already_sold_is_refused_with_a_conflict(): void
    {
        $batch = $this->aBatch();

        $this->sell($batch)->assertSuccessful();

        // The second seller, a moment later. Same answer as before this
        // change — what changed is that it now holds under a race.
        $this->sell($batch)
            ->assertStatus(409)
            ->assertJsonPath('message', 'این چانه قبلاً فروخته شده است.');

        $this->assertSame(1, Sale::count());
    }

    public function test_a_batch_that_sells_still_sells(): void
    {
        $batch = $this->aBatch();

        $this->sell($batch)->assertSuccessful();

        $this->assertSame(1, Sale::count());
        $this->assertSame('sold', $batch->fresh()->status);
    }

    public function test_a_sale_the_recorder_refuses_is_still_a_validation_error(): void
    {
        $batch = $this->aBatch(100);

        // More bread than the batch holds. That is the caller getting it
        // wrong, not somebody being ahead of them, and the two must not
        // come back as the same kind of answer.
        $this->sell($batch, breadCount: 500)->assertStatus(422);

        $this->assertSame(0, Sale::count());
    }

    // ------------------------------------------------- the other claims

    public function test_a_dough_already_shaped_is_refused_with_a_conflict(): void
    {
        $dough = DoughEntry::create([
            'user_id' => $this->seller->id,
            'bag_count' => 1,
        ]);

        $maker = User::factory()->create(['is_active' => true]);
        $maker->assignRole('chane_gir');

        $shape = fn () => $this->actingAs($maker, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'chane_count' => 50,
                'spray_flour_kg' => 1,
            ]);

        $shape()->assertSuccessful();

        // Spray flour leaves the warehouse on each of these. Raced, it
        // left twice against dough that exists once.
        $shape()->assertStatus(409);

        $this->assertSame(1, ChaneEntry::where('dough_entry_id', $dough->id)->count());
    }

    public function test_a_settlement_is_answered_once_however_many_admins_tap(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $batch = $this->aBatch();
        $this->sell($batch);

        $settlement = SettlementRequest::create([
            'user_id' => $this->seller->id,
            'amount' => 500_000,
            'paid_cash' => 500_000,
            'paid_card' => 0,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/settlement-requests/{$settlement->id}/confirm")
            ->assertSuccessful();

        // The second admin. Confirming posts cash to a bank account, so a
        // race here puts a seller's takings on the shop's balance twice.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/settlement-requests/{$settlement->id}/confirm")
            ->assertStatus(409);
    }

    public function test_a_settlement_cannot_be_confirmed_and_rejected_both(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $batch = $this->aBatch();
        $this->sell($batch);

        $settlement = SettlementRequest::create([
            'user_id' => $this->seller->id,
            'amount' => 500_000,
            'paid_cash' => 500_000,
            'paid_card' => 0,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/settlement-requests/{$settlement->id}/confirm")
            ->assertSuccessful();

        // Not only against itself: one admin approving while another turns
        // it down used to leave whichever wrote last, with the money
        // already moved.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/settlement-requests/{$settlement->id}/reject", [
                'reason' => 'اشتباه بود',
            ])
            ->assertStatus(409);

        $this->assertNull($settlement->fresh()->rejected_at);
    }

    private function sell(ChaneEntry $batch, int $breadCount = 100): TestResponse
    {
        return $this->actingAs($this->seller, 'sanctum')->postJson('/api/v1/sales', [
            'chane_entry_id' => $batch->id,
            'payment_type' => 'cash',
            'bread_count' => $breadCount,
            'amount' => $breadCount * 5000,
        ]);
    }
}
