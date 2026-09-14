<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockCount;
use App\Support\AppCalendar;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «چند کیسه در انبار هست؟» — از قفسه پرسیده می‌شود، از دفتر جواب می‌گیرد.
 *
 * دفتر خطای خودش را پیدا نمی‌کند: کیسه‌ای که آمده و فاکتورش ثبت نشده،
 * آردی که ریخته، فرمولی که کمی بیشتر یا کمتر از واقعیت حساب می‌کند — هر
 * کدام دو طرف دفتر را با هم جور می‌گذارد و با قفسه نه.
 *
 * قرینهٔ CashCountController و عمداً هم‌شکل.
 */
class StockCountController extends Controller
{
    use ApiResponse;

    /** چند شمارش در تاریخچه دیده شود. */
    private const HISTORY = 60;

    public function index(Request $request): JsonResponse
    {
        $item = $this->itemFrom($request);

        if (! $item) {
            return $this->error('کالایی با این کلید در انبار نیست.', 422);
        }

        $counts = StockCount::with('user')
            ->where('inventory_item_id', $item->getKey())
            ->latest('counted_at')
            ->limit(self::HISTORY)
            ->get();

        $last = $counts->first();

        return $this->success([
            'item' => $this->itemPayload($item),
            // آنچه دفتر همین حالا می‌گوید، تا صفحه بتواند عددِ مقایسه را
            // پیش از آنکه چیزی تایپ شود نشان بدهد.
            'expected' => $this->quantityPayload($item, round((float) $item->balance, 3)),
            'first_count' => $counts->isEmpty(),
            'last_counted_at' => $last ? AppCalendar::date($last->counted_at) : null,
            'days_since_count' => $last
                ? (int) $last->counted_at->startOfDay()->diffInDays(now()->startOfDay())
                : null,
            'counts' => $counts->map(fn (StockCount $c) => $this->present($c))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item' => ['required', 'string', 'max:50'],
            // به همان واحدی که صفحه نشان می‌دهد: کیسه اگر کالا کیسه‌ای
            // باشد، وگرنه واحد پایه.
            'counted' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
            // آیا دفتر با قفسه یکی شود. پیش‌فرض خاموش و همیشه تصمیم
            // تماس‌گیرنده: اصلاحِ بی‌صدا دقیقاً همان چیزی را پنهان می‌کند
            // که آدم برای پیدا کردنش می‌شمارد.
            'adjust' => ['nullable', 'boolean'],
        ]);

        $item = $this->itemFrom($request);

        if (! $item) {
            return $this->error('کالایی با این کلید در انبار نیست.', 422);
        }

        // شمردن یک چیز است و بازنویسی دفتر چیز دیگر.
        //
        // فروشنده‌ها `manage-inventory` دارند چون محموله ثبت می‌کنند، و
        // خوب است که هر کس با انبار سروکار دارد بتواند بشمارد و بگوید چه
        // دید — شمارش، سند است و هیچ‌وقت ویرایش نمی‌شود.
        //
        // ولی اصلاحِ موجودی، عددی را عوض می‌کند که سفارش آرد و بهای
        // تمام‌شدهٔ نان از رویش بسته می‌شود؛ و امروز معلوم شد ۲۳۶ کیسه
        // اصلاح دستی سال‌ها بی‌آنکه کسی ببیند انجام شده بود. آن تصمیم،
        // تصمیم صاحب مغازه است.
        if (($data['adjust'] ?? false) && ! $request->user()->can('manage-bakery')) {
            return $this->error(
                'شمارش ثبت می‌شود، ولی اصلاح موجودی انبار فقط از دست مدیر برمی‌آید.',
                403,
            );
        }

        $count = DB::transaction(function () use ($data, $item, $request) {
            // داخل تراکنش خوانده می‌شود، تا عددی که به‌عنوان «دفتر» نوشته
            // می‌شود همان باشد که اصلاح از رویش حساب می‌شود.
            $expected = round((float) $item->balance, 3);

            $count = StockCount::create([
                'inventory_item_id' => $item->getKey(),
                'user_id' => $request->user()->id,
                'counted_quantity' => $this->toBaseUnit($item, (float) $data['counted']),
                'expected_quantity' => $expected,
                'counted_at' => now(),
                'note' => $data['note'] ?? null,
            ]);

            if (($data['adjust'] ?? false) && ! $count->is_exact) {
                $gap = $count->difference;

                $item->move(
                    $gap > 0 ? 'in' : 'out',
                    abs($gap),
                    'stocktake',
                    $request->user()->id,
                    $count,
                    'اصلاح پس از شمارش انبار',
                );
            }

            return $count;
        });

        return $this->success(
            $this->present($count->fresh(['user', 'item', 'adjustment'])),
            $count->is_exact
                ? 'شمارش ثبت شد — انبار با دفتر می‌خواند.'
                : 'شمارش ثبت شد.',
            201
        );
    }

    /**
     * کالایی که شمرده می‌شود، پیش‌فرض آرد.
     *
     * فقط کالاهای شناخته‌شدهٔ انبار: یک کلید دلخواه، `ofKey` را وامی‌دارد
     * ردیفی بسازد که هیچ‌کس نخواسته بود.
     */
    private function itemFrom(Request $request): ?InventoryItem
    {
        $key = (string) ($request->input('item') ?? InventoryItem::FLOUR);

        if (! array_key_exists($key, InventoryItem::DEFAULTS)) {
            return null;
        }

        return InventoryItem::ofKey($key);
    }

    /**
     * وزن یک کیسه، یا صفر اگر کالا کیسه‌ای نباشد.
     *
     * از `bagWeightKg()` خودِ کالا، نه از ستونش: برای آرد، وزن کیسه در
     * تنظیمات نانوایی است و ستون ممکن است عدد قدیمی‌تری داشته باشد.
     * همان چیزی که `/inventory` هم به اپ می‌دهد، پس «۱۱۳ کیسه» روی هر دو
     * صفحه یک معنی دارد.
     */
    private function bagWeight(InventoryItem $item): float
    {
        return $item->bagWeightKg();
    }

    /** آنچه روی صفحه گفته می‌شود، به واحد پایه. */
    private function toBaseUnit(InventoryItem $item, float $counted): float
    {
        $bag = $this->bagWeight($item);

        return round($bag > 0 ? $counted * $bag : $counted, 3);
    }

    /** @return array<string, mixed> */
    private function quantityPayload(InventoryItem $item, float $quantity): array
    {
        $bag = $this->bagWeight($item);

        return [
            'base' => round($quantity, 3),
            'base_unit' => $item->unit,
            // کیسه فقط برای نمایش. ذخیره نمی‌شود، چون وزن کیسه در تنظیمات
            // عوض می‌شود و آن‌وقت شمارش‌های قدیمی معنی تازه‌ای می‌گیرند.
            'bags' => $bag > 0 ? round($quantity / $bag, 2) : null,
            'bag_weight_kg' => $bag > 0 ? $bag : null,
        ];
    }

    /** @return array<string, mixed> */
    private function itemPayload(InventoryItem $item): array
    {
        return [
            'key' => $item->key,
            'name' => $item->name,
            'unit' => $item->unit,
            'bag_weight_kg' => $this->bagWeight($item) ?: null,
        ];
    }

    /** @return array<string, mixed> */
    private function present(StockCount $count): array
    {
        $item = $count->item;
        $gap = $count->difference;

        return [
            'id' => $count->id,
            'counted' => $this->quantityPayload($item, (float) $count->counted_quantity),
            'expected' => $this->quantityPayload($item, (float) $count->expected_quantity),
            'difference' => $this->quantityPayload($item, abs($gap)),
            // با کلمه گفته می‌شود و نه فقط با علامت، چون «‎-۸٫۲» که صبح
            // شلوغ یک‌نگاهی خوانده شود همان عددی است که نباید اشتباه
            // خوانده شود.
            'difference_label' => $count->is_exact
                ? 'می‌خواند'
                : ($gap > 0 ? 'اضافه' : 'کسری'),
            'is_exact' => $count->is_exact,
            'adjusted' => $count->adjustment !== null,
            'counted_at' => AppCalendar::date($count->counted_at),
            'counted_by' => $count->user?->name,
            'note' => $count->note,
        ];
    }
}
