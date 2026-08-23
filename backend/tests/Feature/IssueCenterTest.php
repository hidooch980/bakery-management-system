<?php

namespace Tests\Feature;

use App\Filament\Pages\IssueCenter;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Models\User;
use App\Support\IssueScanner;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\SystemIssue;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The issue centre reports what does not add up, and only fixes what can be
 * fixed by adding an explained record rather than editing history.
 */
class IssueCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'flour_bag_weight_kg' => 40,
            'water_ratio' => 0.6,
            'salt_ratio' => 0.015,
            'dough_loss_ratio' => 0,
            // Proving is measured in ProofGainTest; here the
            // formula's own arithmetic is what is under test.
            'proof_gain_ratio' => 0,
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
            'bread_price' => 5000,
            'currency' => 'toman',
        ]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function scan(): Collection
    {
        return app(IssueScanner::class)->scan();
    }

    private function issue(string $key): ?SystemIssue
    {
        return $this->scan()->firstWhere('key', $key);
    }

    /** Forces a balance below zero, which the move() guard normally prevents. */
    private function forceNegativeFlour(float $amount): InventoryItem
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);

        InventoryMovement::create([
            'inventory_item_id' => $flour->id,
            'direction' => 'out',
            'quantity' => $amount,
            'reason' => 'production',
        ]);

        return $flour;
    }

    public function test_a_clean_shop_reports_no_issues(): void
    {
        $this->assertTrue($this->scan()->isEmpty());
    }

    public function test_a_negative_balance_is_reported_as_critical(): void
    {
        $this->forceNegativeFlour(5);

        $issue = $this->issue('negative-stock-flour');

        $this->assertNotNull($issue);
        $this->assertSame(SystemIssue::CRITICAL, $issue->severity);
        $this->assertStringContainsString('-5.000', $issue->detail);
        $this->assertTrue($issue->isAutoFixable());
    }

    public function test_fixing_a_negative_balance_adds_a_movement_rather_than_editing_one(): void
    {
        $flour = $this->forceNegativeFlour(5);
        $movementsBefore = $flour->movements()->count();

        Livewire::test(IssueCenter::class)->callAction('autoFix');

        // Back to zero, and the original outflow is still on the record.
        $this->assertSame(0.0, $flour->fresh()->balance);
        $this->assertSame($movementsBefore + 1, $flour->fresh()->movements()->count());

        $correction = $flour->fresh()->movements()->latest('id')->first();
        $this->assertSame('in', $correction->direction);
        $this->assertStringContainsString('اصلاح خودکار', $correction->note);
    }

    public function test_the_negative_balance_issue_clears_once_it_is_fixed(): void
    {
        $this->forceNegativeFlour(5);
        $this->assertNotNull($this->issue('negative-stock-flour'));

        Livewire::test(IssueCenter::class)->callAction('autoFix');

        // Issues are derived, so putting the data right removes them.
        $this->assertNull($this->issue('negative-stock-flour'));
    }

    public function test_missing_settings_are_reported(): void
    {
        Bakery::first()->update(['bread_price' => null, 'nanino_chane_weight_kg' => null]);

        $issue = $this->issue('missing-settings');

        $this->assertNotNull($issue);
        $this->assertStringContainsString('قیمت نان', $issue->detail);
        $this->assertStringContainsString('وزن چانه نانینو', $issue->detail);
        // Nothing here can be guessed at, so no automatic fix is offered.
        $this->assertFalse($issue->isAutoFixable());
    }

    public function test_an_unsettled_seller_account_is_reported(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $dough = DoughEntry::create(['user_id' => $seller->id, 'bag_count' => 1]);
        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $seller->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'sold',
        ]);
        Sale::create([
            'chane_entry_id' => $chane->id,
            'user_id' => $seller->id,
            'payment_type' => 'cash',
            'bread_count' => 100,
            'amount' => 500_000,
            'amount_difference' => 0,
        ]);

        $issue = $this->issue("seller-account-{$seller->id}");

        $this->assertNotNull($issue);
        $this->assertStringContainsString('500،000', $issue->detail);
        // Cash simply not handed over yet is routine, not a crisis.
        $this->assertSame(SystemIssue::INFO, $issue->severity);
    }

    public function test_a_seller_whose_money_does_not_match_is_critical(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $dough = DoughEntry::create(['user_id' => $seller->id, 'bag_count' => 1]);
        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $seller->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'sold',
        ]);
        Sale::create([
            'chane_entry_id' => $chane->id,
            'user_id' => $seller->id,
            'payment_type' => 'cash',
            'bread_count' => 100,
            'amount' => 450_000,
            'amount_difference' => -50_000,
        ]);

        $issue = $this->issue("seller-account-{$seller->id}");

        $this->assertSame(SystemIssue::CRITICAL, $issue->severity);
        $this->assertStringContainsString('اختلاف مالی', $issue->detail);
        // A discrepancy must never be settled automatically.
        $this->assertFalse($issue->isAutoFixable());
    }

    public function test_dough_left_unshaped_overnight_is_reported(): void
    {
        $entry = DoughEntry::create(['user_id' => $this->admin->id, 'bag_count' => 2]);
        $entry->created_at = now()->subDays(2);
        $entry->save();

        $issue = $this->issue('stale-dough');

        $this->assertNotNull($issue);
        $this->assertStringContainsString('1 دسته خمیر', $issue->detail);
    }

    public function test_dough_from_today_is_not_treated_as_stale(): void
    {
        DoughEntry::create(['user_id' => $this->admin->id, 'bag_count' => 2]);

        $this->assertNull($this->issue('stale-dough'));
    }

    public function test_the_worst_issues_are_listed_first(): void
    {
        Bakery::first()->update(['bread_price' => null]);
        $this->forceNegativeFlour(5);

        $severities = $this->scan()->map->severity->all();

        $this->assertSame(SystemIssue::CRITICAL, $severities[0]);
    }

    public function test_the_page_opens_and_shows_the_issue(): void
    {
        $this->forceNegativeFlour(5);

        Livewire::test(IssueCenter::class)
            ->assertOk()
            ->assertSee('موجودی آرد منفی است')
            ->assertSee('علت احتمالی');
    }

    public function test_the_page_says_so_when_nothing_is_wrong(): void
    {
        Livewire::test(IssueCenter::class)
            ->assertOk()
            ->assertSee('همه چیز مرتب است');
    }

    public function test_the_fix_button_is_hidden_when_nothing_can_be_fixed(): void
    {
        // A missing setting is reported but cannot be fixed automatically.
        Bakery::first()->update(['bread_price' => null]);

        Livewire::test(IssueCenter::class)
            ->assertActionHidden('autoFix');
    }

    /**
     * A month of trading with no wages in it. Payroll is the biggest
     * running cost there is, so its absence has to be said out loud rather
     * than quietly inflating every profit figure.
     */
    private function sellSomething(): Sale
    {
        $dough = DoughEntry::create([
            'user_id' => $this->admin->id,
            'bag_count' => 1,
            'status' => 'shaped',
        ]);

        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->admin->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'sold',
        ]);

        return Sale::create([
            'user_id' => $this->admin->id,
            'chane_entry_id' => $chane->id,
            'payment_type' => 'cash',
            'bread_count' => 10,
            'amount' => 50_000,
        ]);
    }

    /**
     * Nothing is said until the payment is actually due.
     *
     * The old check fired the moment anything was sold, so it shouted
     * «حقوق این ماه ثبت نشده» as critical from this shop's first day of
     * trading — three weeks before its first payday arrived. That is noise
     * wearing a warning's colour, and it is what teaches an owner to stop
     * reading the page.
     */
    private function monthlyIssue(string $key): ?SystemIssue
    {
        return $this->scan()->first(
            fn (SystemIssue $i) => str_starts_with($i->key, "monthly-{$key}-")
        );
    }

    /** Puts the clock inside the window where a payment is chased. */
    private function clockAt(int $daysBeforeMonthEnd): void
    {
        [, $monthEnd] = Jalali::currentMonthRange();

        Carbon::setTestNow($monthEnd->copy()->subDays($daysBeforeMonthEnd)->setTime(12, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_wages_are_not_chased_before_payday(): void
    {
        $this->sellSomething();
        $this->clockAt(10);

        // Ten days of the month still to run. The shop pays at the end of
        // it, so there is nothing late about nothing being recorded.
        $this->assertNull($this->monthlyIssue('wages'));
    }

    public function test_insurance_is_chased_in_the_last_days_of_the_month(): void
    {
        $this->sellSomething();
        $this->clockAt(3);

        $issue = $this->monthlyIssue('insurance');

        $this->assertNotNull($issue);
        // A warning, not a crisis: there are still days to pay it in.
        $this->assertSame(SystemIssue::WARNING, $issue->severity);
    }

    public function test_insurance_is_not_chased_mid_month(): void
    {
        $this->sellSomething();
        $this->clockAt(20);

        $this->assertNull($this->monthlyIssue('insurance'));
    }

    public function test_a_recorded_wage_payment_settles_it(): void
    {
        $this->sellSomething();
        $this->clockAt(0);

        SalaryPayment::create([
            'user_id' => $this->admin->id,
            'period_start' => now()->startOfMonth(),
            'period_label' => 'این ماه',
            'base_amount' => 1_000_000,
            'net_amount' => 1_000_000,
            'paid_on' => now(),
        ]);

        $this->assertNull($this->monthlyIssue('wages'));
    }

    public function test_wages_paid_as_an_expense_settle_it_too(): void
    {
        $this->sellSomething();
        $this->clockAt(0);

        // This shop has paid wages both ways: as payslips and as expenses
        // on the «حقوق کارکنان» category. Looking at only one of them
        // called a paid payroll missing.
        Expense::create([
            'user_id' => $this->admin->id,
            'category' => 'salary',
            'title' => 'علی الحساب عبدالله',
            'amount' => 2_000_000,
            'spent_on' => now(),
        ]);

        $this->assertNull($this->monthlyIssue('wages'));
    }

    public function test_a_recorded_premium_settles_the_insurance(): void
    {
        $this->sellSomething();
        $this->clockAt(3);

        Expense::create([
            'user_id' => $this->admin->id,
            'category' => 'insurance',
            'title' => 'حق بیمه',
            'amount' => 5_000_000,
            'spent_on' => now(),
        ]);

        $this->assertNull($this->monthlyIssue('insurance'));
    }

    public function test_a_month_with_no_sales_says_nothing_about_either(): void
    {
        $this->clockAt(0);

        // No trading is not the same as an unpaid obligation.
        $this->assertNull($this->monthlyIssue('wages'));
        $this->assertNull($this->monthlyIssue('insurance'));
    }

    public function test_expenses_piled_into_other_are_flagged(): void
    {
        Expense::create([
            'user_id' => $this->admin->id,
            'category' => 'other',
            'title' => 'متفرقه',
            'amount' => 800_000,
            'spent_on' => now(),
        ]);

        Expense::create([
            'user_id' => $this->admin->id,
            'category' => 'utilities',
            'title' => 'برق',
            'amount' => 200_000,
            'spent_on' => now(),
        ]);

        $issue = $this->issue('expenses-mostly-other');

        $this->assertNotNull($issue);
        $this->assertSame(SystemIssue::WARNING, $issue->severity);
        $this->assertStringContainsString('80', $issue->detail);
    }

    public function test_well_filed_expenses_are_left_alone(): void
    {
        Expense::create([
            'user_id' => $this->admin->id,
            'category' => 'utilities',
            'title' => 'برق',
            'amount' => 800_000,
            'spent_on' => now(),
        ]);

        Expense::create([
            'user_id' => $this->admin->id,
            'category' => 'other',
            'title' => 'متفرقه',
            'amount' => 200_000,
            'spent_on' => now(),
        ]);

        $this->assertNull($this->issue('expenses-mostly-other'));
    }
}
