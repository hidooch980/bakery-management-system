<?php

namespace App\Models;

use App\Support\Jalali;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    public const CATEGORIES = [
        'flour' => 'خرید آرد',
        'fuel' => 'سوخت',
        'utilities' => 'آب، برق، گاز',
        'rent' => 'اجاره',
        'maintenance' => 'تعمیرات',
        'salary' => 'حقوق کارکنان',
        'other' => 'سایر',
    ];

    protected $fillable = [
        'user_id',
        'category',
        'title',
        'amount',
        'spent_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function getSpentOnJalaliAttribute(): ?string
    {
        return Jalali::date($this->spent_on);
    }
}
