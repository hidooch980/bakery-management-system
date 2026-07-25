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
                // Reference values the app uses to pre-fill chane weights and
                // suggest a sale amount.
                'normal_chane_weight_kg' => 0.430,
                'nanino_chane_weight_kg' => 0.380,
                'bread_price' => 3000,
            ]
        );
    }
}
