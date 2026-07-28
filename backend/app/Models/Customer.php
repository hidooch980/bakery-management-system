<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    public const TYPES = [
        'school' => 'مدرسه',
        'office' => 'اداره',
        'partner' => 'همکار / نانوایی',
        'other' => 'سایر',
    ];

    /** Types that buy bread, as opposed to partner bakeries. */
    public const BUYER_TYPES = ['school', 'office', 'other'];

    public const PARTNER_TYPE = 'partner';

    protected $fillable = [
        'name',
        'type',
        'contact_name',
        'phone',
        'address',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBuyers($query)
    {
        return $query->whereIn('type', self::BUYER_TYPES);
    }

    public function scopePartners($query)
    {
        return $query->where('type', self::PARTNER_TYPE);
    }

    public function interactions()
    {
        return $this->hasMany(CustomerInteraction::class)->latest();
    }

    /** Follow-ups still owed to this customer. */
    public function openFollowUps()
    {
        return $this->hasMany(CustomerInteraction::class)->open();
    }

    /** What this customer still owes, across every unpaid sale. */
    public function getOutstandingAttribute(): float
    {
        return round((float) $this->sales()->outstanding()->sum('amount'), 2);
    }

    public function consignments()
    {
        return $this->hasMany(ConsignmentFlour::class);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
