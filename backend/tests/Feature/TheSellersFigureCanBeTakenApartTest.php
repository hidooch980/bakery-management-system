<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
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
 * «معلوم نیست عدد از کجا آمده».
 *
 * The seller-accounts page showed one figure per person and nothing
 * behind it. Neither the owner nor the seller could check it: the only
 * way to see which sales made up a debt was the panel's sales table,
 * filtered by hand. A disagreement about one day's takings had no way of
 * being had.
 *
 * So the figure comes apart by day — the unit the shop works in — and the
 * same days can be handed over together, which is what a seller back from
 * a week away actually does.
 */
class TheSellersFigureCanBeTakenApartTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['bread_price' => 5000, 'currency' => 'toman']);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        // Somewhere for the notes to land. Without a drawer the handover
        // is recorded against nothing, which is its own bug and has its
        // own test elsewhere.
        BankAccount::create([
            'title' => 'صندوق',
            'is_cash_box' => true,
            'is_active' => true,
        ]);
    }

    public function test_the_figure_comes_apart_into_the_days_that_made_it(): void
    {
        $this->sell(100, 500_000, daysAgo: 2);
        $this->sell(60, 300_000, daysAgo: 1);
        $this->sell(40, 200_000, daysAgo: 1);

        $body = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/seller-accounts/{$this->seller->id}/breakdown")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $body['days']);

        // Newest first — a disagreement is nearly always about yesterday.
        $this->assertSame(now()->subDay()->toDateString(), $body['days'][0]['date']);
        $this->assertSame(2, $body['days'][0]['sale_count']);
        $this->assertSame(100, $body['days'][0]['bread_count']);
        $this->assertEqualsWithDelta(500_000.0, $body['days'][0]['total'], 0.01);

        $this->assertEqualsWithDelta(500_000.0, $body['days'][1]['total'], 0.01);

        // The days have to add up to the figure the page has been showing,
        // or taking it apart has only produced a second answer.
        $this->assertEqualsWithDelta(
            $body['total'],
            array_sum(array_column($body['days'], 'total')),
            0.01,
        );
    }

    public function test_a_gap_in_what_was_handed_over_is_shown_on_its_own_day(): void
    {
        // 100 loaves are worth 500,000; the seller recorded 480,000.
        // The API works the gap out when a sale is recorded through it;
        // here the row is built directly, so the gap is stated.
        $this->sell(100, 480_000, daysAgo: 1, difference: -20_000);

        $day = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/seller-accounts/{$this->seller->id}/breakdown")
            ->assertOk()
            ->json('data.days.0');

        $this->assertEqualsWithDelta(480_000.0, $day['cash'], 0.01);
        $this->assertEqualsWithDelta(-20_000.0, $day['difference'], 0.01);

        // Cash + shortfall − difference. The subtraction is the part
        // people query, which is why the rule is stated in the answer.
        $this->assertEqualsWithDelta(500_000.0, $day['total'], 0.01);
    }

    public function test_the_credit_is_kept_out_of_what_the_seller_can_hand_over(): void
    {
        $this->sell(100, 500_000, daysAgo: 1);
        $this->sell(50, 250_000, daysAgo: 1, type: 'credit');

        $body = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/seller-accounts/{$this->seller->id}/breakdown")
            ->assertOk()
            ->json('data');

        // Money still with the customer is not the seller's to hand over.
        // Counted in, the seller looks short by money they never took.
        $this->assertEqualsWithDelta(250_000.0, $body['credit'], 0.01);
        $this->assertEqualsWithDelta(500_000.0, $body['total'], 0.01);
        $this->assertEqualsWithDelta(500_000.0, $body['days'][0]['total'], 0.01);
    }

    public function test_several_days_are_handed_over_together(): void
    {
        $old = $this->sell(100, 500_000, daysAgo: 3);
        $middle = $this->sell(100, 500_000, daysAgo: 2);
        $recent = $this->sell(100, 500_000, daysAgo: 1);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle", [
                'paid_cash' => 1_000_000,
                'days' => [
                    now()->subDays(3)->toDateString(),
                    now()->subDays(2)->toDateString(),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.settled', true);

        $this->assertNotNull($old->fresh()->cash_settled_on);
        $this->assertNotNull($middle->fresh()->cash_settled_on);

        // The day nobody named is still open — this is the whole point of
        // naming days rather than closing the account.
        $this->assertNull($recent->fresh()->cash_settled_on);
    }

    public function test_a_day_with_nothing_open_is_said_rather_than_settled_silently(): void
    {
        $this->sell(100, 500_000, daysAgo: 1);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle", [
                'paid_cash' => 500_000,
                'days' => [now()->subDays(9)->toDateString()],
            ])
            ->assertStatus(422);
    }

    /**
     * Naming days and handing over less than they come to.
     *
     * A part payment spreads oldest-debt-first across the whole account,
     * so the money would land on a day nobody picked. Refused out loud.
     */
    public function test_chosen_days_must_be_paid_in_full(): void
    {
        $this->sell(100, 500_000, daysAgo: 1);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle", [
                'paid_cash' => 200_000,
                'days' => [now()->subDay()->toDateString()],
            ])
            ->assertStatus(422);
    }

    public function test_a_seller_cannot_read_another_sellers_figure(): void
    {
        $this->sell(100, 500_000, daysAgo: 1);

        $this->actingAs($this->seller, 'sanctum')
            ->getJson("/api/v1/seller-accounts/{$this->seller->id}/breakdown")
            ->assertForbidden();
    }

    public function test_the_money_handed_over_for_chosen_days_reaches_the_till(): void
    {
        $this->sell(100, 500_000, daysAgo: 2);
        $this->sell(100, 500_000, daysAgo: 1);

        $till = BankAccount::cashBox();
        $before = (float) $till->fresh()->balance;

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle", [
                'paid_cash' => 500_000,
                'days' => [now()->subDays(2)->toDateString()],
            ])
            ->assertOk();

        $this->assertEqualsWithDelta(
            $before + 500_000,
            (float) $till->fresh()->balance,
            0.01,
        );
    }

    // ---------------------------------------------------------- helpers

    private function sell(
        int $breadCount,
        float $amount,
        int $daysAgo,
        string $type = 'cash',
        float $difference = 0,
    ): Sale {
        $when = now()->subDays($daysAgo)->setTime(9, 0);

        $dough = DoughEntry::create([
            'user_id' => $this->seller->id,
            'bag_count' => 1,
            'status' => 'processed',
        ]);

        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => $breadCount,
            'normal_weight_kg' => 0,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'sold',
        ]);

        $sale = Sale::create([
            'chane_entry_id' => $chane->id,
            'user_id' => $this->seller->id,
            'payment_type' => $type,
            'bread_count' => $breadCount,
            'amount' => $amount,
            'amount_difference' => $difference,
        ]);

        // Sales are stamped by their creation time, so a row that belongs
        // to another day has to be moved there explicitly.
        $sale->forceFill(['created_at' => $when])->saveQuietly();

        return $sale->fresh();
    }
}
