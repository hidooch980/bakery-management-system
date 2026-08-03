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
        'chane_per_tray',
        'bread_price',
        'flour_price_per_kg',
        'flour_price_per_bag',
        'flour_purchase_price_per_kg',
        'flour_transport_by_factory',
        'currency',
        'flour_bag_weight_kg',
        'water_ratio',
        'salt_ratio',
        'yeast_ratio',
        'dough_loss_ratio',
        'proof_gain_ratio',
        'calendar',
        'chane_start_deadline',
        'baking_start_deadline',
        'late_free_days',
        'late_tier1_last_day',
        'late_tier1_amount',
        'late_tier2_amount',
    ];

    protected function casts(): array
    {
        return [
            'normal_chane_weight_kg' => 'decimal:3',
            'nanino_chane_weight_kg' => 'decimal:3',
            'bread_price' => 'decimal:2',
            'flour_price_per_kg' => 'decimal:2',
            'flour_price_per_bag' => 'decimal:2',
            'flour_purchase_price_per_kg' => 'decimal:2',
            'flour_transport_by_factory' => 'boolean',
            'flour_bag_weight_kg' => 'decimal:3',
            'water_ratio' => 'decimal:3',
            'yeast_ratio' => 'decimal:5',
            'proof_gain_ratio' => 'decimal:4',
            'salt_ratio' => 'decimal:4',
            'dough_loss_ratio' => 'decimal:4',
        ];
    }
}
