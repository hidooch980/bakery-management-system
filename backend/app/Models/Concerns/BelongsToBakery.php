<?php

namespace App\Models\Concerns;

use App\Models\Bakery;
use App\Support\CurrentBakery;
use Illuminate\Database\Eloquent\Builder;

/**
 * هر ردیف را به نانوایی خودش گره می‌زند و همان‌جا نگهش می‌دارد.
 *
 * هر کوئری به نانوایی کسی که وارد شده محدود می‌شود و هر ردیف تازه با
 * همان مهر می‌خورد، تا هیچ صفحه و گزارش و خروجی‌ای لازم نباشد یادش
 * بماند فیلتر بگذارد — یک بار یادنرفتن یعنی نشان‌دادنِ درآمد یک
 * نانوایی به آن یکی.
 *
 * وقتی نانوایی‌ای برای محدودکردن نیست، کنار می‌کشد: نصبِ تازه، دستورِ
 * کنسولی که نگفته کدام نانوایی را می‌گوید، یا seeder ی که دارد
 * اولینش را می‌سازد. پس مغازه‌ای که همیشه یک نانوایی داشته، دقیقاً
 * همان‌طور کار می‌کند که پیش از وجود این همه کار می‌کرد.
 */
trait BelongsToBakery
{
    protected static function bootBelongsToBakery(): void
    {
        static::addGlobalScope('bakery', function (Builder $query) {
            $bakeryId = CurrentBakery::id();

            if ($bakeryId !== null) {
                $query->where($query->getModel()->getTable().'.bakery_id', $bakeryId);
            }
        });

        static::creating(function ($model) {
            $model->bakery_id ??= CurrentBakery::id();
        });
    }

    public function bakery()
    {
        return $this->belongsTo(Bakery::class);
    }

    /** عمداً از نانوایی‌ها رد می‌شود — برای کنسول و گزارش‌هایی که خودشان این تصمیم را دارند. */
    public function scopeAcrossBakeries(Builder $query): Builder
    {
        return $query->withoutGlobalScope('bakery');
    }
}
