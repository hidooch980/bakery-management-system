<?php

namespace App\Models;

use App\Support\CurrentBakery;
use Illuminate\Database\Eloquent\Model;

class Bakery extends Model
{
    /**
     * Settings are held for the length of a request, so an admin saving
     * them and then reading them back would otherwise be handed the copy
     * from before the save — the formula, the currency and the calendar
     * all reading one edit behind.
     */
    protected static function booted(): void
    {
        static::saved(fn () => CurrentBakery::forget());
        static::deleted(fn () => CurrentBakery::forget());
    }

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
        'diesel_litres_per_bag',
        'nanino_per_bag',
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
            'diesel_litres_per_bag' => 'decimal:2',
            'nanino_per_bag' => 'integer',
            'flour_transport_by_factory' => 'boolean',
            'flour_bag_weight_kg' => 'decimal:3',
            'water_ratio' => 'decimal:3',
            'yeast_ratio' => 'decimal:5',
            'proof_gain_ratio' => 'decimal:4',
            'salt_ratio' => 'decimal:4',
            'dough_loss_ratio' => 'decimal:4',
        ];
    }

    /**
     * Everyone who signs in to this shop.
     *
     * Deliberately unscoped by the shop the *reader* belongs to: the point
     * of the relation is to answer for a named shop, and the only screen
     * that asks belongs to the head shop looking at the others.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }
}
