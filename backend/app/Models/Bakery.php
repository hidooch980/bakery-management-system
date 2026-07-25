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
    ];

    protected function casts(): array
    {
        return [
            'normal_chane_weight_kg' => 'decimal:3',
            'nanino_chane_weight_kg' => 'decimal:3',
            'bread_price' => 'decimal:2',
        ];
    }
}
