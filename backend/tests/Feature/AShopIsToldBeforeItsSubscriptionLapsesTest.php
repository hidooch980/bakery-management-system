<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\Subscription;
use App\Support\CurrentBakery;
use App\Support\IssueScanner;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selling this to other bakeries makes «may this shop run» a question,
 * and a question nobody can answer from the database is one that gets
 * answered from memory.
 *
 * A bakery that pays for this should be told it is running out while
 * there is still time to pay, not on the morning it stops.
 */
class AShopIsToldBeforeItsSubscriptionLapsesTest extends TestCase
{
    use RefreshDatabase;

    private Bakery $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->shop = Bakery::query()->oldest('id')->first();
        CurrentBakery::forget();
    }

    public function test_a_shop_that_never_had_a_subscription_is_not_nagged(): void
    {
        // Every shop that was here before any of this was sold, starting
        // with the one it was built for. Telling that owner he owes
        // somebody money would be both wrong and alarming.
        $this->assertNull($this->subscriptionIssue());
    }

    public function test_a_term_with_months_left_says_nothing(): void
    {
        $this->term(endsIn: 200);

        $this->assertNull($this->subscriptionIssue());
    }

    public function test_three_weeks_out_the_shop_is_warned(): void
    {
        $this->term(endsIn: 10);

        $issue = $this->subscriptionIssue();

        $this->assertNotNull($issue);
        $this->assertSame('warning', $issue->severity);
        // Latin digits: the date helper renders them that way, and an
        // assertion written in Persian numerals passes only by accident
        // of which formatter happened to touch the string.
        $this->assertStringContainsString('10 روز مانده', $issue->detail);
    }

    public function test_a_lapsed_term_is_critical_and_says_how_long_ago(): void
    {
        $this->term(endsIn: -5);

        $issue = $this->subscriptionIssue();

        $this->assertNotNull($issue);
        $this->assertSame('critical', $issue->severity);
        $this->assertStringContainsString('5 روز گذشته', $issue->detail);
    }

    /**
     * The question anybody reading this asks.
     *
     * Leaving «does the shop stop tomorrow» unanswered is how a warning
     * becomes a panic on a shop floor.
     */
    public function test_the_warning_says_the_shop_does_not_stop(): void
    {
        $this->term(endsIn: -5);

        $this->assertStringContainsString(
            'متوقف نمی‌شود',
            $this->subscriptionIssue()->suggestion,
        );
    }

    public function test_a_renewal_is_the_term_that_counts(): void
    {
        $this->term(endsIn: -5);
        $this->term(endsIn: 300);

        // The lapsed row is still on file; the shop is not on it.
        $this->assertNull($this->subscriptionIssue());
        $this->assertSame(2, Subscription::count());
    }

    public function test_a_cancelled_term_is_not_the_current_one(): void
    {
        $live = $this->term(endsIn: 300);
        $live->update(['cancelled_at' => now(), 'cancellation_reason' => 'بازگشت وجه']);

        $this->assertNull(Subscription::currentFor($this->shop->id));
    }

    /**
     * The one table that is about bakeries rather than inside one.
     *
     * A shop reading its own entitlement through a scope it also
     * controls is a lock fitted to the inside of the door.
     */
    public function test_the_entitlement_is_not_scoped_by_the_shop_itself(): void
    {
        $other = Bakery::create(['name' => 'نانوایی دوم']);

        Subscription::create([
            'bakery_id' => $other->id,
            'plan' => 'standard',
            'starts_on' => now()->subDay(),
            'ends_on' => now()->addDays(300),
        ]);

        CurrentBakery::actAs($this->shop->id);

        // Visible as a row — the isolation here is by bakery_id in the
        // query, not by a global scope the tenant could be switched out
        // from under.
        $this->assertSame(1, Subscription::count());
        $this->assertNull(Subscription::currentFor($this->shop->id));
        $this->assertNotNull(Subscription::currentFor($other->id));
    }

    // ---------------------------------------------------------- helpers

    private function term(int $endsIn): Subscription
    {
        return Subscription::create([
            'bakery_id' => $this->shop->id,
            'plan' => 'standard',
            'starts_on' => now()->subDays(30),
            'ends_on' => now()->addDays($endsIn),
        ]);
    }

    private function subscriptionIssue()
    {
        return collect((new IssueScanner)->scan())
            ->firstWhere('key', 'subscription-running-out');
    }
}
