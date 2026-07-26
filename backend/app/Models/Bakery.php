<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bakery extends Model
{
    protected $fillable = [
        'name',
        'address',
        'phone',
        'logo',
        'description',
        'normal_chane_weight_kg',
        'nanino_chane_weight_kg',
        'bread_price',
        'flour_price_per_kg',
        'flour_price_per_bag',
        'currency',
        'flour_bag_weight_kg',
        'water_ratio',
        'salt_ratio',
        'dough_loss_ratio',
        'calendar',
    ];

    protected function casts(): array
    {
        return [
            'normal_chane_weight_kg' => 'decimal:3',
            'nanino_chane_weight_kg' => 'decimal:3',
            'bread_price' => 'decimal:2',
            'flour_price_per_kg' => 'decimal:2',
            'flour_price_per_bag' => 'decimal:2',
            'flour_bag_weight_kg' => 'decimal:3',
            'water_ratio' => 'decimal:3',
            'salt_ratio' => 'decimal:4',
            'dough_loss_ratio' => 'decimal:4',
        ];
    }
}
