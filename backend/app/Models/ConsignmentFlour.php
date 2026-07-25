<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsignmentFlour extends Model
{
    // "Flour" is uncountable, so Laravel would guess `consignment_flour`.
    protected $table = 'consignment_flours';

    public const DIRECTIONS = [
        'borrowed' => 'دریافتی از همکار',
        'lent' => 'تحویلی به همکار',
    ];

    protected $fillable = [
        'user_id',
        'customer_id',
        'partner_name',
        'partner_phone',
        'direction',
        'amount_kg',
        'occurred_on',
        'settled_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount_kg' => 'decimal:3',
            'occurred_on' => 'date',
            'settled_on' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function partner()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** The defined partner's name, or the free-text one for older rows. */
    public function getPartnerLabelAttribute(): string
    {
        return $this->partner?->name ?? (string) $this->partner_name;
    }

    public function scopeOutstanding($query)
    {
        return $query->whereNull('settled_on');
    }

    public function getIsSettledAttribute(): bool
    {
        return $this->settled_on !== null;
    }

    public function getDirectionLabelAttribute(): string
    {
        return self::DIRECTIONS[$this->direction] ?? $this->direction;
    }
}
