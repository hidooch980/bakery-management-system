<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\User;
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
        \App\Support\Money::forgetCache();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_creating_a_work_start_redirects_to_the_list(): void
    {
        Livewire::test(\App\Filament\Resources\WorkStartResource\Pages\CreateWorkStart::class)
            ->fillForm([
                'type' => 'chane',
                'date' => \App\Support\Jalali::date(now()),
                'started_at' => now(),
                'user_id' => User::first()->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(\App\Filament\Resources\WorkStartResource::getUrl('index'));

        $this->assertDatabaseCount('work_starts', 1);
    }

    public function test_creating_an_income_redirects_to_the_list(): void
    {
        Livewire::test(\App\Filament\Resources\IncomeResource\Pages\CreateIncome::class)
            ->fillForm([
                'category' => 'rent',
                'title' => 'اجاره انبار',
                'amount' => 200000,
                'received_on' => \App\Support\Jalali::date(now()),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(\App\Filament\Resources\IncomeResource::getUrl('index'));

        $this->assertDatabaseCount('incomes', 1);
    }

    public function test_creating_a_bank_account_redirects_to_the_list(): void
    {
        Livewire::test(\App\Filament\Resources\BankAccountResource\Pages\CreateBankAccount::class)
            ->fillForm([
                'title' => 'حساب جاری',
                'opening_balance' => 1000000,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(\App\Filament\Resources\BankAccountResource::getUrl('index'));

        $this->assertDatabaseCount('bank_accounts', 1);
    }

    public function test_creating_a_bakery_share_redirects_to_the_list(): void
    {
        Livewire::test(\App\Filament\Resources\BakeryShareResource\Pages\CreateBakeryShare::class)
            ->fillForm([
                'name' => 'شریک اول',
                'dang' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(\App\Filament\Resources\BakeryShareResource::getUrl('index'));

        $this->assertDatabaseCount('bakery_shares', 1);
    }

    public function test_creating_a_flour_sale_redirects_to_the_list(): void
    {
        \App\Models\InventoryItem::ofKey('flour')->move('in', 500, 'purchase');

        Livewire::test(\App\Filament\Resources\FlourSaleResource\Pages\CreateFlourSale::class)
            ->fillForm([
                'unit' => 'kg',
                'quantity' => 10,
                'unit_price' => 30000,
                'payment_type' => 'cash',
                'user_id' => User::first()->id,
                'sold_on' => \App\Support\Jalali::date(now()),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(\App\Filament\Resources\FlourSaleResource::getUrl('index'));

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
        Livewire::test(\App\Filament\Resources\ExpenseResource\Pages\CreateExpense::class)
            ->fillForm([
                'category' => 'fuel',
                'title' => 'سوخت',
                'amount' => 100000,
                // spent_on deliberately omitted — left at its default.
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(\App\Filament\Resources\ExpenseResource::getUrl('index'));

        $this->assertDatabaseCount('expenses', 1);
    }
}
