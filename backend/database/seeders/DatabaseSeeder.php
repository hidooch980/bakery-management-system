<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            BakerySeeder::class,
            AdminUserSeeder::class,
            DemoStaffSeeder::class,
        ]);
    }
}
