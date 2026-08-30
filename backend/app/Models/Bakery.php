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

    /**
     * The nanino session, or null if there isn't a readable one.
     *
     * An `encrypted` cast throws when the stored value cannot be
     * decrypted — a rotated APP_KEY, a restore from a dump taken under a
     * different one — and reading it straight would turn that into a 500
     * on the settings screen rather than «you are not connected», which
     * is both true and actionable.
     */
    public function naninoToken(): ?string
    {
        try {
            return $this->nanino_token;
        } catch (\Throwable) {
            return null;
        }
    }

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
            // A nanino session grants the same access as signing in for
            // as long as it lasts, so it does not sit on the disk in
            // clear. There is no password to encrypt — nanino signs in
            // with a captcha and an SMS code — and none is stored.
            'nanino_token' => 'encrypted',
            'nanino_refresh_token' => 'encrypted',
            // See naninoToken(): an encrypted cast throws rather than
            // returning null when the value cannot be decrypted, so it is
            // never read through the attribute directly.
            'nanino_connected_at' => 'datetime',
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
