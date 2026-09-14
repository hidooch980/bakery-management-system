<?php

namespace Tests\Feature;

use App\Models\ChaneEntry;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\IssueDestination;
use App\Support\IssueScanner;
use App\Support\SystemIssue;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * A school that has not paid in four months is on the morning page.
 *
 * Everything else the shop is owed is watched from «امروز» — the cash a
 * seller is holding, a partner's flour, a loan instalment coming due.
 * Money owed by the buyers was not, and it is the one that goes quiet on
 * its own: the debts list is a screen somebody has to decide to open, and
 * nobody opens it looking for bad news.
 */
class ADebtGoingStaleIsSaidOutLoudTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');
    }

    private function creditSale(Customer $customer, int $daysAgo, float $amount = 2_000_000): Sale
    {
        $dough = DoughEntry::create(['user_id' => $this->seller->id, 'bag_count' => 2]);

        $batch = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 2,
        ]);

        $sale = Sale::create([
            'chane_entry_id' => $batch->id,
            'user_id' => $this->seller->id,
            'customer_id' => $customer->id,
            'payment_type' => 'schools',
            'bread_count' => 100,
            'amount' => $amount,
        ]);

        $sale->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $sale;
    }

    private function issues(): Collection
    {
        return (new IssueScanner)->scan()
            ->filter(fn ($i) => str_starts_with($i->key, 'customer-debt-stale-'));
    }

    public function test_a_debt_inside_its_own_month_is_not_chased(): void
    {
        // Thirty days is ordinary here: the schools settle on their own
        // month, and a page that says so every month is a page nobody
        // reads by the third one.
        $school = Customer::create(['name' => 'مدرسه شهید بهشتی', 'type' => 'school']);
        $this->creditSale($school, 20);

        $this->assertTrue($this->issues()->isEmpty());
    }

    public function test_a_debt_two_months_old_is_named(): void
    {
        $school = Customer::create([
            'name' => 'مدرسه شهید بهشتی',
            'type' => 'school',
            'phone' => '09151234567',
        ]);
        $this->creditSale($school, 70);

        $issue = $this->issues()->first();

        $this->assertNotNull($issue);
        $this->assertSame(SystemIssue::INFO, $issue->severity);
        $this->assertStringContainsString('مدرسه شهید بهشتی', $issue->title);
        // The number to call, because the answer to this issue is a phone
        // call and looking it up is the step somebody skips.
        $this->assertStringContainsString('09151234567', $issue->suggestion);
    }

    public function test_four_months_is_louder_than_two(): void
    {
        $school = Customer::create(['name' => 'اداره برق', 'type' => 'office']);
        $this->creditSale($school, 130);

        $this->assertSame(SystemIssue::WARNING, $this->issues()->first()?->severity);
    }

    public function test_one_line_per_customer_however_many_receipts(): void
    {
        // Chasing a debt is a conversation with one school about one
        // number, not about nine receipts.
        $school = Customer::create(['name' => 'مدرسه شهید بهشتی', 'type' => 'school']);
        $this->creditSale($school, 70, 1_000_000);
        $this->creditSale($school, 65, 1_500_000);
        $this->creditSale($school, 61, 500_000);

        $issues = $this->issues();

        $this->assertCount(1, $issues);
        $this->assertSame(3_000_000.0, $issues->first()->magnitude);
        $this->assertStringContainsString('3 فاکتور', $issues->first()->detail);
    }

    public function test_a_customer_with_no_number_on_file_is_told_so(): void
    {
        $school = Customer::create(['name' => 'مدرسه بی‌شماره', 'type' => 'school']);
        $this->creditSale($school, 70);

        $this->assertStringContainsString(
            'شمارهٔ تماسش در سیستم نیست',
            $this->issues()->first()->suggestion,
        );
    }

    public function test_a_debt_that_was_paid_leaves_the_list(): void
    {
        $school = Customer::create(['name' => 'مدرسه شهید بهشتی', 'type' => 'school']);
        $sale = $this->creditSale($school, 70);

        $sale->update(['settled_on' => now()]);

        $this->assertTrue($this->issues()->isEmpty());
    }

    public function test_a_cash_sale_is_not_a_debt(): void
    {
        $school = Customer::create(['name' => 'مدرسه شهید بهشتی', 'type' => 'school']);
        $sale = $this->creditSale($school, 70);
        $sale->update(['payment_type' => 'cash']);

        $this->assertTrue($this->issues()->isEmpty());
    }

    public function test_the_phone_is_sent_to_the_finance_tab(): void
    {
        $this->assertSame('finance', IssueDestination::forKey('customer-debt-stale-7'));
    }
}
