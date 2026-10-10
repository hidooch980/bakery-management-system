<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Model;

/**
 * برگشتِ بخشی (یا همهٔ) کیسه‌های یک ردیف آرد امانی.
 *
 * خودش انبار را جابه‌جا نمی‌کند. بعد از ثبت یا حذف، ردیف اصلی دوباره با
 * انبار هم‌حساب می‌شود (ConsignmentFlour::reconcileStock)، تا همهٔ
 * حرکت‌های انبارِ یک امانت زیر همان یک ردیف بمانند و ممیزی انبار
 * (stock:audit) همان‌طور که بود کار کند.
 */
class ConsignmentFlourReturn extends Model
{
    use BelongsToBakery, RecordsAudit;

    protected $fillable = [
        'consignment_flour_id',
        'user_id',
        'bags',
        'returned_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'bags' => 'decimal:2',
            'returned_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (self $return) {
            $record = $return->consignment()->first();

            $record?->afterReturnRemoved($return);
        });
    }

    public function consignment()
    {
        return $this->belongsTo(ConsignmentFlour::class, 'consignment_flour_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function auditSubject(): ?string
    {
        return 'برگشت آرد امانی — '.$this->bags.' کیسه';
    }
}
