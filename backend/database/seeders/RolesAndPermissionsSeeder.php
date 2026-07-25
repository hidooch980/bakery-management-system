<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'manage-users',
        'manage-bakery',
        'view-all-reports',
        'view-attendance-reports',
        'record-dough',
        'view-own-dough-history',
        'view-pending-dough',
        'record-chane',
        'view-own-chane-history',
        'view-pending-chane',
        'record-sale',
        'view-own-sales',
        'record-attendance',
        'change-password',
        'manage-finance',
        'view-financial-reports',
        'manage-inventory',
        'view-inventory',
        'manage-customers',
        'view-chane-board',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions(self::PERMISSIONS);

        $doughMaker = Role::firstOrCreate(['name' => 'dough_maker', 'guard_name' => 'web']);
        $doughMaker->syncPermissions([
            'record-dough',
            'view-own-dough-history',
            'record-attendance',
            'change-password',
        ]);

        $chaneGir = Role::firstOrCreate(['name' => 'chane_gir', 'guard_name' => 'web']);
        $chaneGir->syncPermissions([
            'view-pending-dough',
            'record-chane',
            'view-own-chane-history',
            'record-attendance',
            'change-password',
        ]);

        // The shater works the oven and only needs to see how many chane are
        // waiting, so the role is deliberately read-only.
        $shater = Role::firstOrCreate(['name' => 'shater', 'guard_name' => 'web']);
        $shater->syncPermissions([
            'view-chane-board',
            'record-attendance',
            'change-password',
        ]);

        $seller = Role::firstOrCreate(['name' => 'seller', 'guard_name' => 'web']);
        $seller->syncPermissions([
            'view-pending-chane',
            'record-sale',
            'view-own-sales',
            'view-chane-board',
            'record-attendance',
            'change-password',
        ]);

        // Chane gir also sees the production board.
        $chaneGir->givePermissionTo('view-chane-board');
    }
}
