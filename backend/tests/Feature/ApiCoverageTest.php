<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\FlourAllocation;
use App\Models\Holiday;
use App\Models\InventoryItem;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Support\Jalali;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the endpoints the feature tests do not reach, so a route cannot
 * quietly break without anything noticing.
 */
class ApiCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['normal_chane_weight_kg' => 0.85]);

        $this->admin = $this->userWithRole('admin');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    // ------------------------------------------------------------- lookups

    public function test_lookup_endpoints_return_their_options(): void
    {
        foreach ([
            '/api/v1/sales/payment-types',
            '/api/v1/expenses/categories',
            '/api/v1/customers/types',
            '/api/v1/holidays/types',
            '/api/v1/users/roles',
        ] as $endpoint) {
            $this->actingAs($this->admin, 'sanctum')
                ->getJson($endpoint)
                ->assertOk()
                ->assertJsonPath('success', true);
        }
    }

    // ---------------------------------------------------------------- auth

    public function test_logout_revokes_the_current_token(): void
    {
        $user = $this->userWithRole('seller');
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/logout')
            ->assertOk();

        // The token row is gone, which is the actual guarantee.
        $this->assertSame(0, $user->fresh()->tokens()->count());

        // Guards cache the resolved user for the lifetime of the test
        // application, so clear them to make the next request re-authenticate
        // the way a separate request would in production.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    // ------------------------------------------------------------- history

    public function test_each_role_reads_only_its_own_history(): void
    {
        $dough = $this->userWithRole('dough_maker');
        $chane = $this->userWithRole('chane_gir');

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 50, 'purchase');

        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_WET)->move('in', 50, 'purchase');
        $this->actingAs($dough, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 1])->assertCreated();

        $this->actingAs($chane, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => DoughEntry::first()->id,
                'chane_count' => 10,
                'spray_flour_kg' => 0,
            ])->assertCreated();

        $this->actingAs($dough, 'sanctum')
            ->getJson('/api/v1/dough-entries/my-history')->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->actingAs($chane, 'sanctum')
            ->getJson('/api/v1/chane-entries/my-history')->assertOk()
            ->assertJsonPath('data.total', 1);

        // The dough maker's history must not contain someone else's chane.
        $this->actingAs($dough, 'sanctum')
            ->getJson('/api/v1/chane-entries/my-history')->assertForbidden();
    }

    public function test_attendance_history_is_per_user(): void
    {
        $a = $this->userWithRole('seller');
        $b = $this->userWithRole('dough_maker');

        $this->actingAs($a, 'sanctum')->postJson('/api/v1/attendance/check-in');
        $this->actingAs($b, 'sanctum')->postJson('/api/v1/attendance/check-in');

        $this->actingAs($a, 'sanctum')
            ->getJson('/api/v1/attendance/my-history')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_seller_sees_the_pending_chane_queue(): void
    {
        $this->givenOneChaneBatch();

        $this->actingAs($this->userWithRole('seller'), 'sanctum')
            ->getJson('/api/v1/chane-entries/pending')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    // ------------------------------------------------------------ inventory

    public function test_inventory_endpoints_report_and_move_stock(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/inventory')
            ->assertOk()
            // The stocked goods are created on first read: flour, salt and
            // the two yeasts. Dough is not among them — it is mixed and
            // shaped the same day and never sits on a shelf.
            ->assertJsonCount(4, 'data');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/inventory/movements', [
                'item' => 'flour',
                'direction' => 'in',
                'quantity' => 250,
                'reason' => 'purchase',
            ])
            ->assertCreated()
            ->assertJsonPath('data.balance', 250);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/inventory/movements')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_flour_is_reported_in_bags_but_salt_only_in_kilograms(): void
    {
        InventoryItem::ofKey('flour')->move('in', 200, 'purchase');
        InventoryItem::ofKey('salt')->move('in', 75, 'purchase');

        InventoryItem::ofKey('yeast_dry')->move('in', 50, 'purchase');
        InventoryItem::ofKey('yeast_wet')->move('in', 50, 'purchase');
        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/inventory')
            ->assertOk()
            ->json('data');

        $flour = collect($data)->firstWhere('key', 'flour');
        $salt = collect($data)->firstWhere('key', 'salt');

        // 200kg at the default 40kg sack.
        $this->assertEqualsWithDelta(5, $flour['balance_bags'], 0.001);
        // Salt has no fixed sack size, so no bag count is invented for it.
        $this->assertNull($salt['balance_bags']);
        $this->assertEqualsWithDelta(75, $salt['balance'], 0.001);
    }

    public function test_inventory_threshold_can_be_set(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/inventory/flour/threshold', ['low_threshold' => 100])
            ->assertOk();

        $this->assertSame('100.000', InventoryItem::ofKey('flour')->low_threshold);
    }

    public function test_inventory_rejects_an_unknown_item(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/inventory/movements', [
                'item' => 'sugar',
                'direction' => 'in',
                'quantity' => 1,
            ])
            ->assertStatus(422);
    }

    public function test_a_seller_may_read_and_move_stock_but_not_the_books(): void
    {
        // The seller sells flour out of the warehouse and books deliveries
        // in, so they hold the stock permissions. Money is still the
        // admin's: seeing what is on the shelf is not seeing the accounts.
        $seller = $this->userWithRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/inventory')->assertOk();

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/inventory/movements', [
                'item' => 'flour',
                'direction' => 'in',
                'quantity' => 100,
            ])
            ->assertCreated();

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/reports/financial')->assertForbidden();
    }

    public function test_production_staff_cannot_touch_inventory(): void
    {
        $this->actingAs($this->userWithRole('dough_maker'), 'sanctum')
            ->getJson('/api/v1/inventory')->assertForbidden();
    }

    // ---------------------------------------------------------- flour stock

    public function test_legacy_flour_stock_endpoints_still_work(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/flour/movements', ['type' => 'in', 'amount_kg' => 50])
            ->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/flour/balance')
            ->assertOk()
            ->assertJsonPath('data.balance_kg', 50);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/flour/movements')->assertOk();
    }

    // ------------------------------------------------------------- quota

    public function test_current_quota_reports_the_active_period(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::currentMonthRange()[0],
            'month_label' => 'تست',
            'total_bags' => 75,
        ]);
        $allocation->syncPeriods();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/flour-allocations/current')
            ->assertOk();

        // Today may fall outside the 5th-to-4th windows, so only assert the
        // shape when a period actually covers it.
        if ($response->json('data') !== null) {
            $response->assertJsonCount(3, 'data.periods');
        }

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/flour-allocations')->assertOk();
    }

    public function test_quota_can_be_updated_and_deleted(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_bags' => 75,
        ]);
        $allocation->syncPeriods();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/flour-allocations/{$allocation->id}", ['total_bags' => 100])
            ->assertOk()
            ->assertJsonPath('data.total_kg', 4000);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/flour-allocations/{$allocation->id}")
            ->assertOk();

        $this->assertDatabaseCount('flour_allocations', 0);
    }

    public function test_duplicate_quota_for_a_month_is_rejected(): void
    {
        $payload = ['month_start' => '1405/05/01', 'total_bags' => 75];

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/flour-allocations', $payload)->assertCreated();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/flour-allocations', $payload)->assertStatus(409);
    }

    // -------------------------------------------------------- consignment

    public function test_consignment_flour_moves_stock_and_settles(): void
    {
        $before = InventoryItem::ofKey('flour')->balance;

        $created = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/consignment-flour', [
                'partner_name' => 'نانوایی رضایی',
                'direction' => 'borrowed',
                'amount_kg' => 200,
            ])
            ->assertCreated()
            ->json('data.id');

        // Borrowed flour physically arrives, so stock rises.
        $this->assertSame($before + 200, InventoryItem::ofKey('flour')->balance);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/consignment-flour/balance')
            ->assertOk()
            ->assertJsonPath('data.borrowed_kg', 200)
            ->assertJsonPath('data.net_kg', -200);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/consignment-flour/{$created}/settle")
            ->assertOk()
            ->assertJsonPath('data.is_settled', true);

        // Settling twice is a conflict, not a silent no-op.
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/consignment-flour/{$created}/settle")
            ->assertStatus(409);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/consignment-flour')->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/consignment-flour/{$created}")->assertOk();
    }

    public function test_lending_flour_reduces_stock(): void
    {
        InventoryItem::ofKey('flour')->move('in', 500, 'purchase');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/consignment-flour', [
                'partner_name' => 'همکار',
                'direction' => 'lent',
                'amount_kg' => 120,
            ])
            ->assertCreated();

        $this->assertSame(380.0, InventoryItem::ofKey('flour')->balance);
    }

    // ---------------------------------------------------------- customers

    public function test_customer_lifecycle(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/customers', [
                'name' => 'دبستان نمونه',
                'type' => 'school',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/customers/{$id}", ['name' => 'دبستان ویرایش‌شده'])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'دبستان ویرایش‌شده');

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/customers/{$id}")->assertOk();
    }

    public function test_inactive_customers_are_hidden_by_default(): void
    {
        Customer::create(['name' => 'فعال', 'type' => 'school', 'is_active' => true]);
        Customer::create(['name' => 'غیرفعال', 'type' => 'school', 'is_active' => false]);

        $this->actingAs($this->userWithRole('seller'), 'sanctum')
            ->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_seller_cannot_manage_customers(): void
    {
        $this->actingAs($this->userWithRole('seller'), 'sanctum')
            ->postJson('/api/v1/customers', ['name' => 'x', 'type' => 'school'])
            ->assertForbidden();
    }

    // ---------------------------------------------------- expenses & salary

    public function test_expense_can_be_updated_and_deleted(): void
    {
        $expense = Expense::create([
            'category' => 'fuel',
            'title' => 'سوخت',
            'amount' => 1000,
            'spent_on' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/expenses/{$expense->id}", ['amount' => 2000])
            ->assertOk()
            ->assertJsonPath('data.amount', 2000);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/expenses/{$expense->id}")->assertOk();
    }

    public function test_salary_can_be_updated_marked_paid_and_deleted(): void
    {
        $employee = $this->userWithRole('seller');

        $salary = SalaryPayment::create([
            'user_id' => $employee->id,
            'period_start' => now()->startOfMonth(),
            'period_label' => 'تست',
            'base_amount' => 1000,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/salaries/{$salary->id}", ['bonus' => 500])
            ->assertOk()
            // Net is derived, so the bonus must show up in it.
            ->assertJsonPath('data.net_amount', 1500);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/salaries/{$salary->id}/mark-paid")->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/salaries/{$salary->id}")->assertOk();
    }

    // ------------------------------------------------------------ holidays

    public function test_holiday_can_be_updated_and_deleted(): void
    {
        $holiday = Holiday::create([
            'date' => now(),
            'title' => 'تعطیل',
            'type' => 'official',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/holidays/{$holiday->id}", ['title' => 'ویرایش شد'])
            ->assertOk()
            ->assertJsonPath('data.title', 'ویرایش شد');

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/holidays/{$holiday->id}")->assertOk();
    }

    // ------------------------------------------------------------- reports

    public function test_production_and_flour_reports_respond(): void
    {
        $this->givenOneChaneBatch();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/reports/production')
            ->assertOk()
            ->assertJsonPath('data.total_dough_bags', 1);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/reports/flour')->assertOk();
    }

    // --------------------------------------------------------- user admin

    public function test_user_can_be_read_toggled_and_updated(): void
    {
        $user = $this->userWithRole('seller');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/users/{$user->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/users/{$user->id}", ['name' => 'نام جدید'])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/users/{$user->id}")->assertOk();
    }

    public function test_admin_cannot_delete_or_disable_themselves(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/users/{$this->admin->id}/toggle-active")
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/users/{$this->admin->id}")
            ->assertStatus(422);
    }

    private function givenOneChaneBatch(): void
    {
        $dough = DoughEntry::create(['user_id' => $this->admin->id, 'bag_count' => 1]);

        ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->admin->id,
            'chane_count' => 10,
            'normal_weight_kg' => 8.5,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
        ]);
    }
}
