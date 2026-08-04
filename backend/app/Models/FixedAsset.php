<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Support\AppCalendar;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Something the shop owns that the day's work never mentions — an oven, a
 * van, the building.
 */
class FixedAsset extends Model
{
    use BelongsToBakery;

    public const CATEGORIES = [
        'equipment' => 'تجهیزات',
        'vehicle' => 'وسیله نقلیه',
        'property' => 'ملک و ساختمان',
        'furniture' => 'اثاثیه',
        'other' => 'سایر',
    ];

    protected $fillable = [
        'title',
        'category',
        'purchase_price',
        'current_value',
        'purchased_on',
        'disposed_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'current_value' => 'decimal:2',
            'purchased_on' => 'date',
            'disposed_on' => 'date',
        ];
    }

    /** Still owned. What has been sold or scrapped is not an asset today. */
    public function scopeHeld(Builder $query): Builder
    {
        return $query->whereNull('disposed_on');
    }

    /**
     * What it is reckoned to be worth.
     *
     * The purchase price stands in until someone says otherwise, because a
     * shop that has not revalued its oven still owns an oven — but a stated
     * value always wins, including a zero one.
     */
    public function getValueAttribute(): float
    {
        return (float) ($this->current_value ?? $this->purchase_price);
    }

    public function getValueFormattedAttribute(): string
    {
        return Money::format($this->value);
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function getPurchasedOnDisplayAttribute(): ?string
    {
        return AppCalendar::date($this->purchased_on);
    }
}
