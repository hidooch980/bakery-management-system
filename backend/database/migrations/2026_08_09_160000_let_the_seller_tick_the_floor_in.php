<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff arrive with flour on their hands and a phone in a locker, and the
 * tick was going unrecorded. The seller is on the floor and can enter it
 * for them.
 *
 * recorded_by is the price of allowing it: an attendance sheet where a
 * ticket someone entered for you is indistinguishable from one you entered
 * yourself is not evidence of anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('recorded_by')
                ->nullable()
                ->after('checked_in_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        $permission = Permission::firstOrCreate([
            'name' => 'record-attendance-for-others',
            'guard_name' => 'web',
        ]);

        foreach (['admin', 'seller'] as $name) {
            Role::where('name', $name)
                ->where('guard_name', 'web')
                ->first()
                ?->givePermissionTo($permission);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by');
        });

        Permission::where('name', 'record-attendance-for-others')
            ->where('guard_name', 'web')
            ->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
