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
        ];
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
