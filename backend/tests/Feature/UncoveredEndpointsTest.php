<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\ConsignmentFlour;
use App\Models\InventoryItem;
use App\Models\SalaryPayment;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoints that had no test of their own. Each one writes money or stock,
 * so they are worth pinning down before a change quietly breaks them.
 */
class UncoveredEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman', 'flour_bag_weight_kg' => 40]);
        \App\Support\Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function account(float $opening = 0): BankAccount
    {
        return BankAccount::create([
            'title' => 'صندوق',
            'opening_balance' => $opening,
            'is_default' => true,
        ]);
    }

    // ------------------------------------------------- bank transactions

    public function test_a_bank_transaction_moves_the_balance(): void
    {
        $account = $this->account(1_000_000);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/bank-accounts/{$account->id}/transactions", [
                'direction' => 'out',
                'amount' => 250_000,
                'note' => 'خرید آرد',
            ])
            ->assertCreated();

        $this->assertEquals(750_000.0, $account->fresh()->balance);
    }

    public function test_the_statement_lists_what_moved(): void
    {
        $account = $this->account(1_000_000);
        $account->record('out', 250_000, 'manual', $this->admin->id, null, 'خرید آرد');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/bank-accounts/{$account->id}/transactions")
            ->assertOk()
            ->assertJsonPath('data.transactions.0.note', 'خرید آرد');
    }

    public function test_a_seller_cannot_touch_the_bank(): void
    {
        $account = $this->account(1_000_000);

        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/bank-accounts/{$account->id}/transactions", [
                'direction' => 'out',
                'amount' => 250_000,
            ])
            ->assertForbidden();
    }

    // ------------------------------------------------------ stock warning

    public function test_the_low_stock_threshold_can_be_set(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/inventory/flour/threshold', ['low_threshold' => 120])
            ->assertOk();

        $flour = InventoryItem::ofKey('flour');
        $flour->move('in', 100, 'purchase');

        $this->assertEquals(120.0, (float) $flour->fresh()->low_threshold);
        $this->assertTrue($flour->fresh()->is_low);
    }

    public function test_clearing_the_threshold_stops_the_warning(): void
    {
        $flour = InventoryItem::ofKey('flour');
        $flour->update(['low_threshold' => 120]);
        $flour->move('in', 100, 'purchase');

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/inventory/flour/threshold', ['low_threshold' => null])
            ->assertOk();

        $this->assertFalse($flour->fresh()->is_low);
    }

    // -------------------------------------------------------- consignment

    public function test_consignment_flour_can_be_settled_once(): void
    {
        InventoryItem::ofKey('flour')->move('in', 1000, 'purchase');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/consignment-flour', [
                'partner_name' => 'نانوایی همسایه',
                'direction' => 'lent',
                'amount_kg' => 80,
            ])
            ->assertCreated();

        $record = ConsignmentFlour::latest('id')->first();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/consignment-flour/{$record->id}/settle")
            ->assertOk();

        // Settling twice would say a debt was repaid that was not.
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/consignment-flour/{$record->id}/settle")
            ->assertStatus(409);
    }

    // ------------------------------------------------------------ payroll

    public function test_a_salary_is_marked_paid_only_once(): void
    {
        $staff = User::factory()->create(['is_active' => true]);
        $staff->assignRole('seller');

        $salary = SalaryPayment::create([
            'user_id' => $staff->id,
            'period_start' => now()->startOfMonth(),
            'period_label' => 'مرداد',
            'base_amount' => 5_000_000,
            'net_amount' => 5_000_000,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/salaries/{$salary->id}/mark-paid")
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/salaries/{$salary->id}/mark-paid")
            ->assertStatus(409);
    }

    // -------------------------------------------------------- user access

    public function test_deactivating_a_user_cuts_their_session(): void
    {
        $staff = User::factory()->create(['is_active' => true]);
        $staff->assignRole('seller');
        $staff->createToken('phone');

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/users/{$staff->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // A revoked account must not keep working on a phone already
        // logged in, or dismissing someone would not take effect.
        $this->assertSame(0, $staff->fresh()->tokens()->count());
    }

    public function test_an_admin_cannot_lock_themselves_out(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/users/{$this->admin->id}/toggle-active")
            ->assertStatus(422);

        $this->assertTrue($this->admin->fresh()->is_active);
    }

    // --------------------------------------------------------- categories

    public function test_income_categories_are_published(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/incomes/categories')
            ->assertOk()
            ->assertJsonStructure(['data' => [['key', 'label']]]);
    }
}
