<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\FixedAsset;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\User;
use App\Support\BalanceSheet;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the shop owns against what it owes.
 */
class BalanceSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function lineOf(array $sheet, string $side, string $key): ?array
    {
        foreach ($sheet[$side] as $line) {
            if ($line['key'] === $key) {
                return $line;
            }
        }

        return null;
    }

    public function test_what_is_in_the_bank_is_an_asset(): void
    {
        BankAccount::create([
            'title' => 'ملی',
            'is_active' => true,
            'opening_balance' => 5_000_000,
        ]);

        $sheet = BalanceSheet::build();

        $this->assertEqualsWithDelta(5_000_000, $this->lineOf($sheet, 'assets', 'bank')['amount'], 0.01);
    }

    public function test_an_oven_is_worth_what_it_cost_until_someone_says_otherwise(): void
    {
        FixedAsset::create([
            'title' => 'تنور',
            'category' => 'equipment',
            'purchase_price' => 40_000_000,
        ]);

        $sheet = BalanceSheet::build();

        $this->assertEqualsWithDelta(
            40_000_000,
            $this->lineOf($sheet, 'assets', 'fixed_assets')['amount'],
            0.01
        );
    }

    public function test_a_stated_value_wins_over_what_it_cost(): void
    {
        // Five years of baking; nobody thinks it is worth the sticker price.
        FixedAsset::create([
            'title' => 'تنور',
            'purchase_price' => 40_000_000,
            'current_value' => 15_000_000,
        ]);

        $sheet = BalanceSheet::build();

        $this->assertEqualsWithDelta(
            15_000_000,
            $this->lineOf($sheet, 'assets', 'fixed_assets')['amount'],
            0.01
        );
    }

    public function test_something_sold_off_stops_being_an_asset(): void
    {
        FixedAsset::create([
            'title' => 'وانت قدیمی',
            'purchase_price' => 100_000_000,
            'disposed_on' => now()->subMonth(),
        ]);

        // The line is dropped entirely rather than shown as a zero.
        $this->assertNull($this->lineOf(BalanceSheet::build(), 'assets', 'fixed_assets'));
    }

    public function test_a_loan_is_owed_until_it_is_paid_off(): void
    {
        $loan = Loan::create([
            'title' => 'وام تجهیزات',
            'principal' => 60_000_000,
            'instalment_amount' => 5_000_000,
            'instalment_count' => 12,
            'first_due_on' => now()->subMonths(2),
        ]);

        LoanPayment::create([
            'loan_id' => $loan->id,
            'amount' => 10_000_000,
            'paid_on' => now(),
        ]);

        $sheet = BalanceSheet::build();

        $this->assertEqualsWithDelta(
            50_000_000,
            $this->lineOf($sheet, 'liabilities', 'loans')['amount'],
            0.01
        );
        $this->assertEqualsWithDelta(50_000_000, $loan->fresh()->remaining, 0.01);
        $this->assertSame(2, $loan->fresh()->instalments_paid);
    }

    public function test_overpaying_settles_a_loan_rather_than_reversing_it(): void
    {
        $loan = Loan::create(['title' => 'وام', 'principal' => 10_000_000]);

        LoanPayment::create([
            'loan_id' => $loan->id,
            'amount' => 12_000_000,
            'paid_on' => now(),
        ]);

        // Not minus two million owed back by the lender.
        $this->assertEqualsWithDelta(0, $loan->fresh()->remaining, 0.01);
    }

    public function test_paying_an_instalment_takes_the_money_out_of_the_bank(): void
    {
        $account = BankAccount::create([
            'title' => 'ملی',
            'is_active' => true,
            'is_default' => true,
            'opening_balance' => 20_000_000,
        ]);

        $loan = Loan::create(['title' => 'وام', 'principal' => 60_000_000]);

        LoanPayment::create([
            'loan_id' => $loan->id,
            'bank_account_id' => $account->id,
            'amount' => 5_000_000,
            'paid_on' => now(),
        ]);

        // Paying a debt must not leave the shop looking richer.
        $this->assertEqualsWithDelta(15_000_000, $account->fresh()->balance, 0.01);
    }

    public function test_what_is_left_over_is_the_shops_own(): void
    {
        BankAccount::create(['title' => 'ملی', 'is_active' => true, 'opening_balance' => 50_000_000]);
        FixedAsset::create(['title' => 'تنور', 'purchase_price' => 30_000_000]);
        Loan::create(['title' => 'وام', 'principal' => 20_000_000]);

        $sheet = BalanceSheet::build();

        $this->assertEqualsWithDelta(80_000_000, $sheet['asset_total'], 0.01);
        $this->assertEqualsWithDelta(20_000_000, $sheet['liability_total'], 0.01);
        $this->assertEqualsWithDelta(60_000_000, $sheet['equity'], 0.01);
        $this->assertTrue($sheet['is_solvent']);
    }

    public function test_owing_more_than_is_held_is_said_plainly(): void
    {
        BankAccount::create(['title' => 'ملی', 'is_active' => true, 'opening_balance' => 5_000_000]);
        Loan::create(['title' => 'وام', 'principal' => 40_000_000]);

        $sheet = BalanceSheet::build();

        $this->assertLessThan(0, $sheet['equity']);
        $this->assertFalse($sheet['is_solvent']);
    }

    public function test_the_admin_can_read_the_sheet(): void
    {
        BankAccount::create(['title' => 'ملی', 'is_active' => true, 'opening_balance' => 1_000_000]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/reports/balance-sheet')
            ->assertOk()
            ->assertJsonPath('data.is_solvent', true)
            ->assertJsonStructure([
                'data' => ['assets', 'liabilities', 'equity', 'fixed_assets', 'loans'],
            ]);
    }

    public function test_staff_cannot_read_the_sheet(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/reports/balance-sheet')
            ->assertForbidden();
    }
}
