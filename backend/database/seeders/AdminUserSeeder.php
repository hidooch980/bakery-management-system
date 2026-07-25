<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@bakery.test'],
            [
                'name' => 'مدیر نانوایی',
                'phone' => '09120000000',
                'password' => 'Admin@12345',
                'is_active' => true,
            ]
        );

        $admin->syncRoles(['admin']);
    }
}
