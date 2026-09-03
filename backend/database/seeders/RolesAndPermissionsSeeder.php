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
        'record-flour-sale',
        'view-own-flour-sales',
        'record-attendance',
        'record-attendance-for-others',
        'change-password',
        'manage-finance',
        'view-financial-reports',
        'manage-inventory',
        'view-inventory',
        'manage-customers',
        'view-chane-board',
        'record-work-start',
        'view-work-start-report',
        // Suppliers, invoices and what the shop owes each mill.
        'manage-purchases',
        // Writing down a delivery that has just arrived, and nothing else.
        'record-purchase',
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
            'record-work-start',
            'view-pending-dough',
            'record-chane',
            'view-own-chane-history',
            'record-attendance',
            'change-password',
        ]);

        // The shater works the oven and only needs to see how many chane are
        // waiting, so the role is deliberately read-only. Baking start is
        // the seller's call, not the shater's, so no record-work-start here.
        $shater = Role::firstOrCreate(['name' => 'shater', 'guard_name' => 'web']);
        $shater->syncPermissions([
            'view-chane-board',
            'record-attendance',
            'change-password',
        ]);

        // In a shop this size the seller is often the only one on the floor,
        // so they can work every step rather than a batch waiting for the
        // person whose job it nominally is. They still cannot touch money
        // beyond their own sales, staff accounts, or the shop's settings.
        $seller = Role::firstOrCreate(['name' => 'seller', 'guard_name' => 'web']);
        $seller->syncPermissions([
            'record-work-start',
            'view-pending-chane',
            'record-sale',
            'view-own-sales',
            // The seller also sells flour straight out of the warehouse.
            'record-flour-sale',
            'view-own-flour-sales',
            // Kneading and shaping, so a batch is never held up.
            'record-dough',
            'view-pending-dough',
            'view-own-dough-history',
            'record-chane',
            'view-own-chane-history',
            // Flour arriving, and flour lent to or borrowed from a partner.
            'view-inventory',
            'manage-inventory',
            'view-chane-board',
            'record-attendance',
            // Who is in today — the whole floor, not just themselves.
            'view-attendance-reports',
            // The floor works with flour on their hands and phones in a
            // locker; the seller is holding one, so they tick people in.
            'record-attendance-for-others',
            // The lorry arrives while the owner is out. The seller writes
            // down what came off it; what the shop owes for it, and the
            // paying, stay with whoever holds the money.
            'record-purchase',
            'change-password',
        ]);

        // Chane gir also sees the production board.
        $chaneGir->givePermissionTo('view-chane-board');
    }
}
