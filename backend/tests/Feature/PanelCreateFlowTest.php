<?php

namespace Tests\Feature;

use App\Filament\Resources\BakeryShareResource;
use App\Filament\Resources\BakeryShareResource\Pages\CreateBakeryShare;
use App\Filament\Resources\BankAccountResource;
use App\Filament\Resources\BankAccountResource\Pages\CreateBankAccount;
use App\Filament\Resources\ExpenseResource;
use App\Filament\Resources\ExpenseResource\Pages\CreateExpense;
use App\Filament\Resources\FlourSaleResource;
use App\Filament\Resources\FlourSaleResource\Pages\CreateFlourSale;
use App\Filament\Resources\IncomeResource;
use App\Filament\Resources\IncomeResource\Pages\CreateIncome;
use App\Filament\Resources\WorkStartResource;
use App\Filament\Resources\WorkStartResource\Pages\CreateWorkStart;
use App\Models\Bakery;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reproduces the "create button does nothing" complaint for the resources
 * built this session, by filling and submitting the real panel form the
 * way a user does — not just checking the page loads.
 */
class PanelCreateFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_creating_a_work_start_redirects_to_the_list(): void
    {
        Livewire::test(CreateWorkStart::class)
            ->fillForm([
                'type' => 'chane',
                'date' => Jalali::date(now()),
                'started_at' => now(),
                'user_id' => User::first()->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(WorkStartResource::getUrl('index'));

        $this->assertDatabaseCount('work_starts', 1);
    }

    public function test_creating_an_income_redirects_to_the_list(): void
    {
        Livewire::test(CreateIncome::class)
            ->fillForm([
                'category' => 'rent',
                'title' => 'اجاره انبار',
                'amount' => 200000,
                'received_on' => Jalali::date(now()),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(IncomeResource::getUrl('index'));

        $this->assertDatabaseCount('incomes', 1);
    }

    public function test_creating_a_bank_account_redirects_to_the_list(): void
    {
        Livewire::test(CreateBankAccount::class)
            ->fillForm([
                'title' => 'حساب جاری',
                'opening_balance' => 1000000,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(BankAccountResource::getUrl('index'));

        $this->assertDatabaseCount('bank_accounts', 1);
    }

    public function test_creating_a_bakery_share_redirects_to_the_list(): void
    {
        Livewire::test(CreateBakeryShare::class)
            ->fillForm([
                'name' => 'شریک اول',
                'dang' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(BakeryShareResource::getUrl('index'));

        $this->assertDatabaseCount('bakery_shares', 1);
    }

    public function test_creating_a_flour_sale_redirects_to_the_list(): void
    {
        InventoryItem::ofKey('flour')->move('in', 500, 'purchase');

        Livewire::test(CreateFlourSale::class)
            ->fillForm([
                'unit' => 'kg',
                'quantity' => 10,
                'unit_price' => 30000,
                'payment_type' => 'cash',
                'user_id' => User::first()->id,
                'sold_on' => Jalali::date(now()),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(FlourSaleResource::getUrl('index'));

        $this->assertDatabaseCount('flour_sales', 1);
    }

    /**
     * The real-world version of the "create button does nothing" report:
     * an admin who never touches the date field at all, leaving it at
     * whatever JalaliDateInput::today() defaults to. That default must
     * satisfy the field's own validation, or every date field defaulting
     * to "today" fails on a completely untouched form.
     */
    public function test_an_untouched_date_field_does_not_fail_its_own_default(): void
    {
        Livewire::test(CreateExpense::class)
            ->fillForm([
                'category' => 'fuel',
                'title' => 'سوخت',
                'amount' => 100000,
                // spent_on deliberately omitted — left at its default.
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(ExpenseResource::getUrl('index'));

        $this->assertDatabaseCount('expenses', 1);
    }
}
