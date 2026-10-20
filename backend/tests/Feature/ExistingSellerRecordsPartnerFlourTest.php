<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ExistingSellerRecordsPartnerFlourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
    }

    public function test_فروشنده_قدیمی_پس_از_مهاجرت_آرد_همکار_را_ثبت_میکند(): void
    {
        // وضعیت نصب قدیمی: فروشنده مجوز مدیریت انبار یا مجوز جدید را ندارد.
        $role = Role::findByName('seller', 'web');
        $role->revokePermissionTo(['manage-inventory', 'record-consignment-flour']);
        Permission::findByName('record-consignment-flour', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole($role);
        $this->actingAs($seller, 'sanctum')->postJson('/api/v1/consignment-flour', [
            'partner_name' => 'همکار آزمایشی', 'direction' => 'borrowed', 'bags' => 2,
        ])->assertForbidden();

        $migration = require database_path('migrations/2026_10_19_100000_allow_existing_sellers_to_record_partner_flour.php');
        $migration->up();
        $migration->up();
        $seller->unsetRelation('roles')->unsetRelation('permissions');

        $before = InventoryItem::ofKey(InventoryItem::FLOUR)->balance;
        $response = $this->actingAs($seller, 'sanctum')->postJson('/api/v1/consignment-flour', [
            'partner_name' => 'همکار آزمایشی', 'direction' => 'borrowed', 'bags' => 2,
        ])->assertCreated();

        $this->assertGreaterThan($before, InventoryItem::ofKey(InventoryItem::FLOUR)->balance);
        $this->actingAs($seller, 'sanctum')->postJson('/api/v1/consignment-flour', [
            'partner_name' => 'همکار آزمایشی', 'direction' => 'lent', 'bags' => 1,
        ])->assertCreated();

        foreach (['/customers?partners_only=1', '/consignment-flour', '/consignment-flour/partners', '/consignment-flour/balance'] as $path) {
            $this->actingAs($seller, 'sanctum')->getJson('/api/v1'.$path)->assertOk();
        }

        // اعطای ثبت آرد همکار، اختیار حذف یا مدیریت سایر قسمت‌های انبار نیست.
        $this->assertFalse($seller->can('manage-inventory'));
        $this->actingAs($seller, 'sanctum')->deleteJson('/api/v1/consignment-flour/'.$response->json('data.id'))->assertForbidden();
        $this->actingAs($seller, 'sanctum')->postJson('/api/v1/flour-allocations', [])->assertForbidden();
        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/reports/financial')->assertForbidden();
    }

    public function test_مجوز_جدید_به_سایر_کارکنان_داده_نمیشود(): void
    {
        $worker = User::factory()->create(['is_active' => true]);
        $worker->assignRole('dough_maker');

        $this->actingAs($worker, 'sanctum')->postJson('/api/v1/consignment-flour', [
            'partner_name' => 'همکار آزمایشی', 'direction' => 'borrowed', 'bags' => 2,
        ])->assertForbidden();
    }
}
