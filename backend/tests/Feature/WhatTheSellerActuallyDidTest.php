<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seller the owner could not see.
 *
 * Until now the admin app showed a seller in exactly one place — the list
 * of what is outstanding — and that list filters out anybody at zero. So
 * a seller who sells all day and hands the money over the same evening
 * appeared nowhere, and the only sellers the owner ever saw were the ones
 * who were behind.
 *
 * These tests are about that first: that a settled-up seller is present.
 * Then about the two figures that must not be blurred into the takings —
 * shortfall, and a debt that is older than the period being read.
 */
class WhatTheSellerActuallyDidTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $hasan;

    private User $karim;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');

        $this->hasan = User::factory()->create(['name' => 'حسن', 'is_active' => true]);
        $this->hasan->assignRole('seller');

        $this->karim = User::factory()->create(['name' => 'کریم', 'is_active' => true]);
        $this->karim->assignRole('seller');
    }

    /**
     * A sale needs a batch behind it, so the chain is built each time. The
     * same shape SalesBreakdownTest uses; there is no ChaneEntry factory.
     */
    private function sell(User $seller, array $attributes = []): Sale
    {
        $breadCount = $attributes['bread_count'] ?? 100;

        $dough = DoughEntry::create(['user_id' => $seller->id, 'bag_count' => 1]);

        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $seller->id,
            'chane_count' => $breadCount,
            'normal_weight_kg' => $breadCount * 0.85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
        ]);

        $sale = Sale::create([
            'chane_entry_id' => $chane->id,
            'user_id' => $seller->id,
            'payment_type' => 'cash',
            'bread_count' => 100,
            'amount' => 1_000_000,
            ...$attributes,
        ]);

        // `created_at` is not fillable, so passing it above is silently
        // dropped and every sale lands today. Four tests here first
        // "passed" that way — which would have left the period filter and
        // the day grouping asserted by tests that never exercised them.
        if (isset($attributes['created_at'])) {
            $sale->forceFill(['created_at' => $attributes['created_at']])->saveQuietly();
            $sale->refresh();
        }

        return $sale;
    }

    private function board(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/reports/sellers?'.http_build_query($query));
    }

    public function test_a_seller_who_owes_nothing_is_still_shown(): void
    {
        // The whole point. Settled up the same day, and therefore absent
        // from every seller surface this app had.
        $this->sell($this->hasan, ['settled_on' => now()]);

        $response = $this->board();

        $response->assertOk();
        $this->assertSame('حسن', $response->json('data.sellers.0.name'));
        $this->assertSame(100, $response->json('data.sellers.0.bread_count'));
    }

    public function test_a_seller_who_sold_nothing_is_shown_as_zero_not_hidden(): void
    {
        $this->sell($this->hasan);

        $names = collect($this->board()->json('data.sellers'))->pluck('name');

        // Absent and idle are different facts, and only one of them is a
        // reason to go and ask somebody a question.
        $this->assertTrue($names->contains('کریم'));
    }

    public function test_the_busiest_seller_is_first(): void
    {
        $this->sell($this->hasan, ['bread_count' => 50]);
        $this->sell($this->karim, ['bread_count' => 300]);

        $sellers = $this->board()->json('data.sellers');

        // Ordered by what was sold. Alphabetical would answer a question
        // nobody asked.
        $this->assertSame('کریم', $sellers[0]['name']);
        $this->assertSame('حسن', $sellers[1]['name']);
    }

    public function test_shortfall_is_reported_beside_the_takings_not_inside_them(): void
    {
        $this->sell($this->hasan, [
            'bread_count' => 100,
            'amount' => 1_000_000,
            'shortfall_count' => 12,
            'shortfall_amount' => 120_000,
        ]);

        $seller = $this->board()->json('data.sellers.0');

        // Bread that left with no money behind it is a different fact
        // from a quiet week. Folded into the takings it disappears.
        $this->assertSame(12, $seller['shortfall_count']);
        $this->assertSame(Money::format(120_000), $seller['shortfall_formatted']);
        $this->assertSame(Money::format(1_000_000), $seller['amount_formatted']);
    }

    public function test_days_active_counts_days_sold_on_not_days_in_the_period(): void
    {
        $this->sell($this->hasan, ['created_at' => now()->subDays(2)]);
        $this->sell($this->hasan, ['created_at' => now()->subDays(2)]);
        $this->sell($this->hasan, ['created_at' => now()]);

        // Two days, three sales. Dividing by the length of the period
        // would punish a man for the shop being shut and for his own days
        // off alike.
        $this->assertSame(2, $this->board()->json('data.sellers.0.days_active'));
        $this->assertSame(3, $this->board()->json('data.sellers.0.sale_count'));
    }

    public function test_each_payment_type_is_broken_out(): void
    {
        $this->sell($this->hasan, ['payment_type' => 'cash', 'bread_count' => 100]);
        $this->sell($this->hasan, ['payment_type' => 'credit', 'bread_count' => 40]);

        $types = collect($this->board()->json('data.sellers.0.by_payment_type'));

        $this->assertSame('cash', $types[0]['payment_type']);
        $this->assertSame('نقد', $types[0]['label']);
        $this->assertSame('نسیه', $types->firstWhere('payment_type', 'credit')['label']);
    }

    public function test_the_period_is_respected(): void
    {
        $this->sell($this->hasan, ['created_at' => now()->subMonths(4)]);

        // Long before any plausible current quota period.
        $this->assertSame(0, $this->board()->json('data.sellers.0.bread_count'));
    }

    public function test_an_old_debt_is_shown_even_when_it_predates_the_period(): void
    {
        // Cash, unsettled. A credit sale would be the wrong instrument
        // here: SellerSettlement keeps credit out of `total` on purpose,
        // because that money is in a customer's pocket and not in his.
        // The first version of this test used one and was asserting
        // something the shop does not mean.
        $this->sell($this->hasan, [
            'created_at' => now()->subMonths(4),
            'payment_type' => 'cash',
            'amount' => 5_000_000,
        ]);

        $seller = $this->board()->json('data.sellers.0');

        // Nothing sold this period, but the money is still in his pocket.
        // A debt does not belong to the week it was run up in, so this one
        // figure is deliberately not period-scoped.
        $this->assertSame(0, $seller['bread_count']);
        $this->assertNotSame(Money::format(0), $seller['outstanding_formatted']);
    }

    public function test_credit_he_let_out_is_shown_apart_from_what_he_can_hand_over(): void
    {
        $this->sell($this->hasan, ['payment_type' => 'credit', 'amount' => 3_000_000]);

        $seller = $this->board()->json('data.sellers.0');

        // Two different facts. `outstanding` is what he can hand over
        // today; credit is bread he let out on trust and cannot. Reporting
        // only the first made the second invisible.
        $this->assertSame(Money::format(0), $seller['outstanding_formatted']);
        $this->assertSame(Money::format(3_000_000), $seller['credit_out_formatted']);
    }

    // ------------------------------------------------- the itemised view

    public function test_one_sellers_sales_come_back_grouped_by_day(): void
    {
        $this->sell($this->hasan, ['created_at' => now()->subDay(), 'bread_count' => 30]);
        $this->sell($this->hasan, ['created_at' => now(), 'bread_count' => 40]);
        $this->sell($this->hasan, ['created_at' => now(), 'bread_count' => 20]);

        $days = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/reports/sellers/{$this->hasan->id}")
            ->assertOk()
            ->json('data.days');

        $this->assertCount(2, $days);
        $this->assertSame(60, $days[0]['bread_count']);
        $this->assertCount(2, $days[0]['lines']);
    }

    public function test_another_sellers_lines_are_not_mixed_in(): void
    {
        $this->sell($this->hasan);
        $this->sell($this->karim);

        $days = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/reports/sellers/{$this->hasan->id}")
            ->json('data.days');

        $this->assertCount(1, $days);
        $this->assertCount(1, $days[0]['lines']);
    }

    public function test_a_line_says_enough_to_be_asked_about(): void
    {
        $this->sell($this->hasan, [
            'payment_type' => 'credit',
            'bread_count' => 25,
            'note' => 'مدرسه شهید بهشتی',
        ]);

        $line = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/reports/sellers/{$this->hasan->id}")
            ->json('data.days.0.lines.0');

        // The owner reads this standing next to the person. A row that
        // says only a number gives him nothing to ask about.
        $this->assertSame('نسیه', $line['payment_label']);
        $this->assertSame(25, $line['bread_count']);
        $this->assertSame('مدرسه شهید بهشتی', $line['note']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $line['at']);
    }

    public function test_a_line_with_no_shortfall_says_nothing_rather_than_zero(): void
    {
        $this->sell($this->hasan);

        $line = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/reports/sellers/{$this->hasan->id}")
            ->json('data.days.0.lines.0');

        // A zero on every ordinary line is noise, and noise is what makes
        // the one line that matters easy to scroll past.
        $this->assertNull($line['shortfall_formatted']);
    }

    public function test_a_seller_cannot_read_the_board(): void
    {
        // It names what every other seller sold. Behind view-all-reports,
        // like the rest of them.
        $this->actingAs($this->hasan, 'sanctum')
            ->getJson('/api/v1/reports/sellers')
            ->assertForbidden();

        $this->actingAs($this->hasan, 'sanctum')
            ->getJson("/api/v1/reports/sellers/{$this->karim->id}")
            ->assertForbidden();
    }

    public function test_money_reads_in_the_shops_unit(): void
    {
        $this->sell($this->hasan, ['amount' => 1_000_000]);

        // Stored in Toman, read in Rial. The owner reads Rial, and a
        // missing zero here is the kind of thing he spots and nobody can
        // explain.
        $this->assertSame(
            Money::format(1_000_000),
            $this->board()->json('data.sellers.0.amount_formatted'),
        );
        $this->assertSame('ریال', $this->board()->json('data.currency_label'));
    }
}
