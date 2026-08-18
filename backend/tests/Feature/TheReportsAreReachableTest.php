<?php

namespace Tests\Feature;

use App\Filament\Pages\BalanceSheetPage;
use App\Filament\Pages\ProfitAndLoss;
use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\Loan;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Two reports that existed and could not be opened.
 *
 * `BalanceSheet` and `Ledger` have been written, tested and answering on
 * the API for weeks. Neither had a page in the panel. The 1,543,344,000
 * Rial of loan missing from this shop's books was found by reading
 * BalanceSheet from a command line — the owner had no screen that would
 * have shown it to him.
 *
 * A report nobody can reach is a report the shop does not have, so these
 * tests are about reachability first and arithmetic second.
 */
class TheReportsAreReachableTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');

        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_balance_sheet_opens(): void
    {
        Livewire::test(BalanceSheetPage::class)
            ->assertOk()
            ->assertSee('دارایی‌ها')
            ->assertSee('بدهی‌ها');
    }

    public function test_the_profit_and_loss_opens(): void
    {
        Livewire::test(ProfitAndLoss::class)
            ->assertOk()
            ->assertSee('درآمد')
            ->assertSee('سود دوره');
    }

    public function test_the_balance_sheet_shows_what_the_shop_owes(): void
    {
        Loan::create([
            'title' => 'وام دستگاه',
            'principal' => 300_000_000,
            'instalment_amount' => 4_000_000,
            'instalment_count' => 40,
            'first_due_on' => now()->subMonths(2),
        ]);

        $sheet = (new BalanceSheetPage)->sheet();

        $this->assertGreaterThan(0, $sheet['liability_total']);
    }

    public function test_a_negative_equity_with_no_fixed_asset_is_explained(): void
    {
        Loan::create([
            'title' => 'وام دستگاه',
            'principal' => 300_000_000,
            'instalment_amount' => 4_000_000,
            'instalment_count' => 40,
            'first_due_on' => now()->subMonths(2),
        ]);

        $page = new BalanceSheetPage;

        // The loan bought a machine the owner chose not to record. Without
        // saying so, the page reads as «this shop is insolvent» when what
        // it means is «half the entry is missing, deliberately».
        $this->assertTrue($page->equityIsMissingAnAsset($page->sheet()));

        Livewire::test(BalanceSheetPage::class)
            ->assertSee('این عدد منفی، همهٔ داستان نیست.');
    }

    public function test_the_explanation_goes_away_once_the_asset_is_recorded(): void
    {
        Loan::create([
            'title' => 'وام دستگاه',
            'principal' => 300_000_000,
            'instalment_amount' => 4_000_000,
            'instalment_count' => 40,
            'first_due_on' => now()->subMonths(2),
        ]);

        FixedAsset::create([
            'title' => 'دستگاه نانوایی',
            'purchase_price' => 300_000_000,
            'purchased_on' => now()->subMonths(2),
        ]);

        $page = new BalanceSheetPage;

        $this->assertFalse($page->equityIsMissingAnAsset($page->sheet()));
    }

    public function test_a_solvent_shop_is_not_told_anything_is_missing(): void
    {
        BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 50_000_000,
            'is_active' => true,
        ]);

        $page = new BalanceSheetPage;

        // No debt, no warning. It fires on the shape of the problem, not
        // on the absence of fixed assets, which most shops will have none
        // of and be perfectly fine.
        $this->assertFalse($page->equityIsMissingAnAsset($page->sheet()));
    }

    public function test_the_statement_adds_up(): void
    {
        Expense::create([
            'title' => 'گازوئیل',
            'category' => 'fuel',
            'amount' => 1_000_000,
            'spent_on' => now(),
        ]);

        $page = new ProfitAndLoss;
        $page->period = 'month';

        $s = $page->statement();

        // The three cost lines are the whole of the total, so a reader can
        // check the page against itself. A statement whose parts do not
        // make its total is one nobody trusts twice.
        $parts = collect($s['costs'])->sum('amount');

        $this->assertEqualsWithDelta($parts, $s['expense_total'], 0.01);
        $this->assertEqualsWithDelta(
            $s['income_total'] - $s['expense_total'],
            $s['profit'],
            0.01,
        );
    }

    public function test_the_period_can_be_changed(): void
    {
        $page = new ProfitAndLoss;

        foreach (['quota', 'month', 'quota_previous', 'month_previous'] as $period) {
            $page->period = $period;

            [$from, $to] = $page->range();

            $this->assertTrue($from->lessThan($to), "$period gave an empty range");
        }
    }

    public function test_the_previous_period_does_not_overlap_this_one(): void
    {
        $page = new ProfitAndLoss;

        $page->period = 'month';
        [$thisFrom] = $page->range();

        $page->period = 'month_previous';
        [, $lastTo] = $page->range();

        // Off by a day either way and a month's takings are counted twice
        // or not at all.
        $this->assertTrue($lastTo->lessThan($thisFrom));
    }
}
