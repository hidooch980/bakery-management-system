<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    public const TYPES = [
        'school' => 'مدرسه',
        'office' => 'اداره',
        'other' => 'سایر',
    ];

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

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
