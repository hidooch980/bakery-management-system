<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\Expense;
use App\Models\Income;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BalanceSheet;
use App\Support\CurrentBakery;
use App\Support\Ledger;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * پولِ یک نانوایی در دفترهای آن یکی شمرده نمی‌شود.
 *
 * جاروی مسیرها ثابت کرد یک نانوایی ردیفِ آن یکی را *نمی‌خواند*. این
 * سؤالِ دیگری است و خطرش بزرگ‌تر: ارقامِ جمع‌شده. یک مسیرِ نشتی را
 * آدم می‌بیند — نام کسی که نمی‌شناسد روی صفحه می‌آید. ولی عددی که
 * ۱۶۴ میلیون بیشتر از واقعیت است، فقط یک عدد است؛ کسی نمی‌داند
 * اشتباه است و همان را باور می‌کند.
 *
 * و سه جا هست که این می‌توانست پیش بیاید و فقط «تصادفاً» نیامده:
 *
 *   • `PurchaseItem` هیچ scope ای ندارد — یکی از آن پنج مدل. در
 *     [Ledger] از راه دو رابطهٔ scope‌دار فیلتر می‌شود و در
 *     [BalanceSheet] از راه `inventory_item_id`. هیچ‌کدام فیلترِ
 *     صریحِ نانوایی نیست.
 *   • ویجتِ حساب فروشنده‌ها `User` را بدون scope می‌پرسد و فقط
 *     به‌خاطر scope روی `sales` درست درمی‌آید.
 *
 * استدلال می‌گوید هر سه امن‌اند. این فایل به‌جای استدلال، می‌سنجد.
 */
class YekNanvaeePoolAnYekiRaNemiShomaradTest extends TestCase
{
    use RefreshDatabase;

    private Bakery $ours;

    private Bakery $theirs;

    private User $ourOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->ours = Bakery::create(['name' => 'نانوایی ما', 'currency' => 'toman']);
        $this->theirs = Bakery::create(['name' => 'نانوایی دیگر', 'currency' => 'toman']);
        Money::forgetCache();

        $this->ourOwner = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $this->ours->id,
        ]);
        $this->ourOwner->assignRole('admin');

        CurrentBakery::forget();
    }

    public function test_هزینهٔ_نانوایی_دیگر_در_دفتر_ما_نمی‌آید(): void
    {
        $this->spendInTheOtherShop(50_000_000);

        [$from, $to] = [now()->startOfMonth(), now()->endOfMonth()];

        $ours = CurrentBakery::for(
            $this->ours->id,
            fn () => Ledger::operatingExpenses($from, $to),
        );

        $this->assertEqualsWithDelta(0.0, $ours, 0.01);
    }

    public function test_درآمد_نانوایی_دیگر_در_دفتر_ما_نمی‌آید(): void
    {
        CurrentBakery::for($this->theirs->id, function () {
            $owner = User::factory()->create([
                'is_active' => true,
                'bakery_id' => $this->theirs->id,
            ]);

            Income::create([
                'user_id' => $owner->id,
                'category' => 'other',
                'title' => 'درآمد آن یکی',
                'amount' => 90_000_000,
                'received_on' => now(),
            ]);
        });

        $ours = CurrentBakery::for(
            $this->ours->id,
            fn () => (float) Income::query()->sum('amount'),
        );

        $this->assertEqualsWithDelta(0.0, $ours, 0.01);
    }

    /**
     * خطِ فاکتورِ آرد، که هیچ scope ای ندارد.
     *
     * اینجا **دو** محافظِ مستقل دارد، و این را با خراب‌کردن فهمیدم نه
     * با خواندن: رابطهٔ `purchase` (که scope دارد) و رابطهٔ `item`
     * (که آن هم scope دارد). برداشتنِ هر کدام به‌تنهایی کاری نمی‌کند،
     * چون آن یکی هنوز سر جایش است — آزمون زیرِ هر دو خرابکاریِ تکی
     * سبز ماند و هیچ چیز ثابت نمی‌کرد.
     *
     * با برداشتنِ هر دو با هم، ۴۰ میلیون آردِ نانوایی دیگر مستقیم در
     * دفترِ ما نشست. یعنی این آزمون واقعاً چیزی را نگه می‌دارد، و آنچه
     * نگه داشته می‌شود «حداقل یکی از آن دو» است.
     *
     * اگر روزی هر دو با هم برداشته شوند، آردِ نانوایی دیگر به بهای
     * تمام‌شدهٔ نانِ ما اضافه می‌شود — و هیچ صفحه‌ای نمی‌گوید چرا نان
     * گران‌تر شده.
     */
    public function test_آردی_که_نانوایی_دیگر_خریده_بهای_نان_ما_را_بالا_نمی‌برد(): void
    {
        $this->buyFlourInTheOtherShop(40_000_000);

        [$from, $to] = [now()->startOfMonth(), now()->endOfMonth()];

        $ours = CurrentBakery::for(
            $this->ours->id,
            fn () => Ledger::flourPurchases($from, $to),
        );

        $this->assertEqualsWithDelta(0.0, $ours, 0.01);
    }

    /**
     * ترازنامه، که قیمتِ هر کیلو را از خطِ فاکتور می‌خواند.
     *
     * فیلترش `inventory_item_id` است نه نانوایی. امن است چون انبارِ
     * هر نانوایی ردیف‌های خودش را دارد — ولی این را کسی تا امروز
     * نسنجیده بود.
     */
    public function test_ترازنامهٔ_ما_انبار_آن_یکی_را_قیمت_نمی‌گذارد(): void
    {
        $this->buyFlourInTheOtherShop(40_000_000);

        $sheet = CurrentBakery::for(
            $this->ours->id,
            fn () => BalanceSheet::build(),
        );

        $this->assertIsArray($sheet);

        // انبارِ ما خالی است. هر عددی جز صفر یعنی کیسه‌های آن یکی
        // را دارایی ما حساب کرده.
        $stock = data_get($sheet, 'assets.stock.amount', data_get($sheet, 'stock', 0));

        $this->assertEqualsWithDelta(0.0, (float) $stock, 0.01);
    }

    // ------------------------------------------------------ کمک‌کننده‌ها

    private function spendInTheOtherShop(float $amount): void
    {
        CurrentBakery::for($this->theirs->id, function () use ($amount) {
            $owner = User::factory()->create([
                'is_active' => true,
                'bakery_id' => $this->theirs->id,
            ]);

            Expense::create([
                'category' => 'fuel',
                'title' => 'هزینهٔ آن یکی',
                'amount' => $amount,
                'spent_on' => now(),
                'user_id' => $owner->id,
            ]);
        });
    }

    private function buyFlourInTheOtherShop(float $amount): void
    {
        CurrentBakery::for($this->theirs->id, function () use ($amount) {
            $owner = User::factory()->create([
                'is_active' => true,
                'bakery_id' => $this->theirs->id,
            ]);

            $supplier = Supplier::create(['name' => 'آسیابِ آن یکی']);

            $purchase = Purchase::create([
                'supplier_id' => $supplier->id,
                'user_id' => $owner->id,
                'purchased_on' => now(),
            ]);

            PurchaseItem::create([
                'purchase_id' => $purchase->id,
                'inventory_item_id' => InventoryItem::ofKey(InventoryItem::FLOUR)->id,
                'quantity' => 1000,
                'unit_price' => $amount / 1000,
                'amount' => $amount,
            ]);
        });
    }
}
