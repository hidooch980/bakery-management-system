<?php

namespace Tests\Feature;

use App\Filament\Resources\BankAccountResource\Pages\EditBankAccount;
use App\Filament\Resources\BankAccountResource\RelationManagers\TransactionsRelationManager;
use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Everything the account really does that the shop records nowhere else —
 * a cash withdrawal for the day's buying, a transfer, a bank charge — had
 * no way in, so the balance on screen drifted from the balance at the bank
 * with nothing able to say why.
 */
class BankStatementByHandTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BankAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->account = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 10_000_000,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function statement(): Testable
    {
        return Livewire::test(TransactionsRelationManager::class, [
            'ownerRecord' => $this->account,
            'pageClass' => EditBankAccount::class,
        ]);
    }

    private function enter(array $overrides = []): void
    {
        $this->statement()->callTableAction('create', data: [
            'direction' => 'out',
            'amount' => 3_000_000,
            'reason' => 'manual',
            'occurred_on' => now()->toDateString(),
            ...$overrides,
        ]);
    }

    public function test_a_withdrawal_can_be_entered_by_hand(): void
    {
        $this->enter(['note' => 'برداشت نقدی']);

        $this->assertSame(1, BankTransaction::count());
        $this->assertEquals(7_000_000.0, $this->account->fresh()->balance);
    }

    public function test_a_deposit_moves_the_balance_the_other_way(): void
    {
        $this->enter(['direction' => 'in', 'amount' => 5_000_000]);

        $this->assertEquals(15_000_000.0, $this->account->fresh()->balance);
    }

    public function test_it_records_who_entered_it(): void
    {
        $this->enter();

        $this->assertSame($this->admin->id, BankTransaction::first()->user_id);
    }

    public function test_the_amount_is_typed_in_the_shops_display_unit(): void
    {
        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        $this->enter(['amount' => 30_000_000]); // Rial

        // Stored in Toman, a tenth of what was typed — the error that hit
        // expenses, payslips and the flour price before this.
        $this->assertEquals(3_000_000.0, (float) BankTransaction::first()->amount);
    }

    public function test_a_hand_entered_row_can_be_changed_and_removed(): void
    {
        $this->enter();
        $row = BankTransaction::first();

        $this->statement()->assertTableActionVisible('edit', $row);
        $this->statement()->assertTableActionVisible('delete', $row);
    }

    public function test_a_row_posted_by_an_expense_cannot_be_touched_here(): void
    {
        Expense::create([
            'user_id' => $this->admin->id,
            'category' => 'utilities',
            'title' => 'برق',
            'amount' => 500_000,
            'spent_on' => now(),
            'bank_account_id' => $this->account->id,
        ]);

        $posted = BankTransaction::first();

        // It belongs to the expense and is rebuilt from it on every save,
        // so an edit here would only hold until the expense was touched.
        $this->assertNotNull($posted->source_type);
        $this->statement()->assertTableActionHidden('edit', $posted);
        $this->statement()->assertTableActionHidden('delete', $posted);
    }

    public function test_a_hand_entered_row_survives_an_expense_being_resaved(): void
    {
        $expense = Expense::create([
            'user_id' => $this->admin->id,
            'category' => 'utilities',
            'title' => 'برق',
            'amount' => 500_000,
            'spent_on' => now(),
            'bank_account_id' => $this->account->id,
        ]);

        $this->enter(['amount' => 1_000_000]);

        // Rebuilding the expense's posting must not sweep up rows that were
        // never its to begin with.
        $expense->update(['amount' => 600_000]);

        $this->assertSame(1, BankTransaction::whereNull('source_type')->count());
    }

    public function test_the_reason_cannot_claim_to_be_a_sale(): void
    {
        // A row calling itself a sale but attached to no sale would make
        // the takings report disagree with the sales list, so the picker
        // offers only the two a person enters by hand — and the form
        // refuses anything else rather than trusting the picker to be the
        // only way in.
        $this->statement()
            ->callTableAction('create', data: [
                'direction' => 'out',
                'amount' => 1_000_000,
                'reason' => 'sale',
                'occurred_on' => now()->toDateString(),
            ])
            ->assertHasTableActionErrors(['reason']);

        $this->assertSame(0, BankTransaction::count());
    }
}
