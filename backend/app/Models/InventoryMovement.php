<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    public const REASONS = [
        'manual' => 'ثبت دستی',
        'purchase' => 'خرید',
        'production' => 'مصرف در تولید',
        'spray' => 'آرد پاششی',
        'waste' => 'ضایعات',
        'consignment_in' => 'دریافت امانی',
        'consignment_out' => 'تحویل امانی',
    ];

    protected $fillable = [
        'inventory_item_id',
        'user_id',
        'direction',
        'quantity',
        'reason',
        'source_type',
        'source_id',
        'note',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function getReasonLabelAttribute(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }
}
