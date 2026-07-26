<?php

namespace App\Models;

use App\Models\Concerns\PostsToBankAccount;
use App\Support\Jalali;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use PostsToBankAccount;

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
        'bank_account_id',
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

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function getSpentOnJalaliAttribute(): ?string
    {
        return Jalali::date($this->spent_on);
    }

    // ------------------------------------------------- bank posting

    public function bankPostingAccountId(): ?int
    {
        return $this->bank_account_id;
    }

    public function bankPostingAmount(): float
    {
        return (float) $this->amount;
    }

    public function bankPostingReason(): string
    {
        return 'expense';
    }

    public function bankPostingDate()
    {
        return $this->spent_on ?? now();
    }
}
