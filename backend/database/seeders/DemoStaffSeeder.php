<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DemoStaffSeeder extends Seeder
{
    public function run(): void
    {
        $staff = [
            ['name' => 'رضا خمیرگیر', 'phone' => '09121111111', 'email' => 'dough@bakery.test', 'role' => 'dough_maker'],
            ['name' => 'علی چانه‌گیر', 'phone' => '09122222222', 'email' => 'chane@bakery.test', 'role' => 'chane_gir'],
            ['name' => 'حسن شاطر', 'phone' => '09124444444', 'email' => 'shater@bakery.test', 'role' => 'shater'],
            ['name' => 'محمد فروشنده', 'phone' => '09123333333', 'email' => 'seller@bakery.test', 'role' => 'seller'],
        ];

        foreach ($staff as $person) {
            $user = User::firstOrCreate(
                ['email' => $person['email']],
                [
                    'name' => $person['name'],
                    'phone' => $person['phone'],
                    'password' => 'Staff@12345',
                    'is_active' => true,
                ]
            );

            $user->syncRoles([$person['role']]);
        }
    }
}
