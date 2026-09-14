<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
use App\Support\AppCalendar;
use Illuminate\Database\Eloquent\Model;

/**
 * شمارش آنچه در انبار هست، در برابر آنچه دفتر انتظار داشت.
 *
 * قرینهٔ CashCount و عمداً هم‌شکل: اختلاف ذخیره نمی‌شود بلکه محاسبه
 * می‌شود — عددی که ذخیره شود، عددی است که کسی می‌تواند به توافق
 * ویرایشش کند، و این رکورد دقیقاً برای مخالفت وجود دارد.
 *
 * اصلاح دفتر تصمیم جداگانه‌ای است که تماس‌گیرنده می‌گیرد و به شکل یک
 * حرکت انبار با دلیل `stocktake` نوشته می‌شود که به همین شمارش اشاره
 * دارد. پس تاریخچه این‌طور خوانده می‌شود: انبار این‌قدر کم داشت، و این
 * کاری بود که شد — یا نشد، که آن هم دانستنش می‌ارزد.
 */
class StockCount extends Model
{
    use BelongsToBakery, RecordsAudit;

    protected $fillable = [
        'inventory_item_id',
        'user_id',
        'counted_quantity',
        'expected_quantity',
        'counted_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'counted_quantity' => 'decimal:3',
            'expected_quantity' => 'decimal:3',
            'counted_at' => 'datetime',
        ];
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** حرکتی که برای این شمارش نوشته شد، اگر نوشته شد. */
    public function adjustment()
    {
        return $this->morphOne(InventoryMovement::class, 'source');
    }

    /**
     * شمرده منهای دفتر. مثبت یعنی در انبار بیشتر از آنچه دفتر می‌داند،
     * منفی یعنی انبار کم دارد.
     */
    public function getDifferenceAttribute(): float
    {
        return round(
            (float) $this->counted_quantity - (float) $this->expected_quantity,
            3,
        );
    }

    /**
     * آیا اختلاف به اندازه‌ای هست که حرفی داشته باشد.
     *
     * یک گرم نه. آرد با ترازوی کیسه‌ای شمرده می‌شود و کسری اعشار، خطای
     * اندازه‌گیری است نه خبر.
     */
    public function getIsExactAttribute(): bool
    {
        return abs($this->difference) < 0.01;
    }

    /** یک خط که این شمارش را نام می‌برد. */
    public function describe(): string
    {
        $gap = $this->difference;
        $unit = $this->item?->unit ?? '';

        return AppCalendar::date($this->counted_at).' — '
            .($this->is_exact
                ? 'خواند'
                : ($gap > 0 ? 'اضافه ' : 'کسری ')
                    .rtrim(rtrim(number_format(abs($gap), 3), '0'), '.').' '.$unit);
    }

    /**
     * How this row names itself in the trail.
     *
     * The log outlives the record: once the row is gone its id points at
     * nothing, and this sentence is all that is left to argue from.
     */
    public function auditSubject(): ?string
    {
        return trim('شمارش انبار '.($this->item?->name ?? ''));
    }
}
