<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // سیدر روی سرورهای موجود دوباره اجرا نمی‌شود؛ مجوز همین‌جا اعمال می‌شود.
        $permission = Permission::firstOrCreate([
            'name' => 'record-consignment-flour',
            'guard_name' => 'web',
        ]);

        foreach (['admin', 'seller'] as $name) {
            Role::where('name', $name)->where('guard_name', 'web')->first()
                ?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // مجوز جدید حذف می‌شود؛ مجوزهای قبلی مدیریت انبار دست‌نخورده می‌مانند.
        Permission::where('name', 'record-consignment-flour')
            ->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
