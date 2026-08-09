<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The seeder has given the seller these since the shop went live, but the
 * production database was seeded before that and seeders do not re-run. The
 * result was a seller who could sell bread but not record the dough or chane
 * they had just made themselves — on a floor where they are often the only
 * one working.
 *
 * view-pending-dough comes along because the chane screen is built on it:
 * without the list of dough waiting to be shaped, the screen opens empty and
 * record-chane has nothing to act on.
 */
return new class extends Migration
{
    private const GRANTED = [
        'record-dough',
        'view-pending-dough',
        'view-own-dough-history',
        'record-chane',
        'view-own-chane-history',
        'view-attendance-reports',
    ];

    public function up(): void
    {
        $seller = Role::where('name', 'seller')->where('guard_name', 'web')->first();

        if (! $seller) {
            return;
        }

        foreach (self::GRANTED as $name) {
            $permission = Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);

            $seller->givePermissionTo($permission);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $seller = Role::where('name', 'seller')->where('guard_name', 'web')->first();

        if (! $seller) {
            return;
        }

        foreach (self::GRANTED as $name) {
            $seller->revokePermissionTo($name);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
