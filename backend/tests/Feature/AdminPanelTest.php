<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function staff(string $role): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_login_page_is_reachable(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('سیستم مدیریت نانوایی');
    }

    public function test_guest_is_redirected_from_panel(): void
    {
        $this->get('/admin')->assertRedirect();
    }

    #[DataProvider('panelPages')]
    public function test_admin_can_open_panel_page(string $path): void
    {
        $this->actingAs($this->admin())
            ->get($path)
            ->assertOk();
    }

    public static function panelPages(): array
    {
        return [
            'dashboard' => ['/admin'],
            'users list' => ['/admin/users'],
            'user create' => ['/admin/users/create'],
            'dough entries' => ['/admin/dough-entries'],
            'dough create' => ['/admin/dough-entries/create'],
            'chane entries' => ['/admin/chane-entries'],
            'chane create' => ['/admin/chane-entries/create'],
            'sales' => ['/admin/sales'],
            'sale create' => ['/admin/sales/create'],
            'attendances' => ['/admin/attendances'],
            'flour stock' => ['/admin/flour-stock-movements'],
            'bakery settings' => ['/admin/manage-bakery'],
            'expenses' => ['/admin/expenses'],
            'expense create' => ['/admin/expenses/create'],
            'salaries' => ['/admin/salary-payments'],
            'salary create' => ['/admin/salary-payments/create'],
            'inventory items' => ['/admin/inventory-items'],
            'inventory movements' => ['/admin/inventory-movements'],
            'inventory movement create' => ['/admin/inventory-movements/create'],
            'flour allocations' => ['/admin/flour-allocations'],
            'flour allocation create' => ['/admin/flour-allocations/create'],
            'consignment flour' => ['/admin/consignment-flours'],
            'consignment create' => ['/admin/consignment-flours/create'],
            'customers' => ['/admin/customers'],
            'customer create' => ['/admin/customers/create'],
            'holidays' => ['/admin/holidays'],
            'holiday create' => ['/admin/holidays/create'],
            'flour sales' => ['/admin/flour-sales'],
            'flour sale create' => ['/admin/flour-sales/create'],
            'incomes' => ['/admin/incomes'],
            'income create' => ['/admin/incomes/create'],
            'bakery shares' => ['/admin/bakery-shares'],
            'bakery share create' => ['/admin/bakery-shares/create'],
            'bank accounts' => ['/admin/bank-accounts'],
            'bank account create' => ['/admin/bank-accounts/create'],
        ];
    }

    public function test_dashboard_renders_with_data_in_every_widget(): void
    {
        $admin = $this->admin();

        // This class does not seed the bakery, so create it here.
        $this->seed(\Database\Seeders\BakerySeeder::class);

        \App\Models\Bakery::first()->update([
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
            'currency' => 'rial',
        ]);
        \App\Support\Money::forgetCache();

        // Production, stock, a quota, a debt and an attendance record, so no
        // widget is exercised only against empty tables.
        $dough = \App\Models\DoughEntry::create(['user_id' => $admin->id, 'bag_count' => 2]);
        $chane = \App\Models\ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $admin->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 40,
            'spray_flour_kg' => 2,
        ]);

        $customer = \App\Models\Customer::create(['name' => 'دبستان', 'type' => 'school']);
        \App\Models\Sale::create([
            'chane_entry_id' => $chane->id,
            'user_id' => $admin->id,
            'payment_type' => 'credit',
            'customer_id' => $customer->id,
            'amount' => 500000,
        ]);

        \App\Models\Expense::create([
            'category' => 'fuel',
            'title' => 'سوخت',
            'amount' => 100000,
            'spent_on' => now(),
        ]);

        \App\Models\InventoryItem::ofKey('flour')->move('in', 400, 'purchase');

        $allocation = \App\Models\FlourAllocation::create([
            'month_start' => \App\Support\Jalali::currentMonthRange()[0],
            'month_label' => 'تست',
            'total_bags' => 75,
            'carryover_bags' => 10,
        ]);
        $allocation->syncPeriods();

        // A bank account and a partner, so the two widgets that hide
        // themselves when empty are actually exercised.
        $account = \App\Models\BankAccount::create([
            'title' => 'حساب جاری',
            'opening_balance' => 5000000,
            'is_default' => true,
        ]);
        $account->record('in', 250000);

        \App\Models\BakeryShare::create(['name' => 'شریک', 'dang' => 6]);

        \App\Models\Income::create([
            'category' => 'rent',
            'title' => 'اجاره',
            'amount' => 200000,
            'received_on' => now(),
            'bank_account_id' => $account->id,
        ]);

        \App\Models\InventoryItem::ofKey('flour')->move('in', 200, 'purchase');
        \App\Models\FlourSale::create([
            'user_id' => $admin->id,
            'unit' => 'bag',
            'quantity' => 1,
            'unit_price' => 1200000,
            'payment_type' => 'cash',
            'sold_on' => now(),
        ]);

        \App\Models\Attendance::create([
            'user_id' => $admin->id,
            'date' => now(),
            'checked_in_at' => now(),
        ]);

        $this->actingAs($admin)->get('/admin')->assertOk();

        // The widgets exercised above no longer all live on the dashboard —
        // several moved to the list page of the resource they're actually
        // about, so this data needs to reach them there instead.
        $this->actingAs($admin)->get('/admin/sales')->assertOk();
        $this->actingAs($admin)->get('/admin/expenses')->assertOk();
        $this->actingAs($admin)->get('/admin/attendances')->assertOk();
        $this->actingAs($admin)->get('/admin/bank-accounts')->assertOk();
        $this->actingAs($admin)->get('/admin/work-starts')->assertOk();
    }

    public function test_non_admin_staff_cannot_access_panel(): void
    {
        foreach (['dough_maker', 'chane_gir', 'seller'] as $role) {
            $this->actingAs($this->staff($role))
                ->get('/admin')
                ->assertForbidden();
        }
    }

    public function test_deactivated_admin_cannot_access_panel(): void
    {
        $admin = $this->admin();
        $admin->update(['is_active' => false]);

        $this->actingAs($admin)->get('/admin')->assertForbidden();
    }
}
