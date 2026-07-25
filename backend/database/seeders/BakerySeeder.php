<?php

namespace Database\Seeders;

use App\Models\Bakery;
use Illuminate\Database\Seeder;

class BakerySeeder extends Seeder
{
    public function run(): void
    {
        Bakery::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'نانوایی سنتی نمونه',
                'address' => 'تهران، خیابان نمونه',
                'phone' => '02100000000',
                'description' => 'نانوایی نمونه برای سیستم مدیریت',
            ]
        );
    }
}
