<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\IssueScanner;
use App\Support\SystemIssue;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * آردی که بدون فاکتور وارد انبار می‌شود، گفته می‌شود.
 *
 * ۱۴۰۵/۰۶/۲۳ انبار شمرده شد و دفتر ۸ کیسه کم داشت. دفتر آرد نشان داد این
 * بار اول نبوده: پنج بار موجودی دستی بالا رفته بود، جمعاً ۲۳۶ کیسه، هر
 * پنج بار در یک جهت — در برابر ۵۵۷ کیسه خرید ثبت‌شدهٔ کل تاریخ مغازه.
 *
 * اصلاح موجودی این را حل نمی‌کند، پنهانش می‌کند. خریدی که ثبت نشود سه عدد
 * را هم‌زمان دروغ می‌گوید: موجودی انبار، بدهی به کارخانه، و بهای
 * تمام‌شدهٔ نان — و دو تای آخر با شمردن انبار هم پیدا نمی‌شوند.
 */
class FlourArrivingWithoutAnInvoiceIsSaidOutLoudTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private InventoryItem $flour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['flour_bag_weight_kg' => 40]);

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');

        $this->flour = InventoryItem::ofKey(InventoryItem::FLOUR);
    }

    private function stock(float $kg): void
    {
        $this->flour->move('in', $kg, 'purchase', $this->owner->id);
    }

    private function bake(float $kg, int $daysAgo = 1): void
    {
        $this->travel(-$daysAgo)->days();
        $this->flour->move('out', $kg, 'production', $this->owner->id);
        $this->travelBack();
    }

    /** @return array<int, SystemIssue> */
    private function issues(): array
    {
        return collect((new IssueScanner)->scan())
            ->filter(fn ($issue) => $issue->key === 'flour-purchase-gap')
            ->values()
            ->all();
    }

    public function test_baking_with_no_purchase_on_record_at_all_is_said(): void
    {
        // آرد از راه اصلاح موجودی آمده، نه فاکتور — دقیقاً وضعیتی که
        // مغازه در آن بود.
        $this->flour->move('in', 5_000, 'correction', $this->owner->id);
        $this->bake(2_000);

        $issue = $this->issues()[0] ?? null;

        $this->assertNotNull($issue);
        $this->assertStringContainsString('هیچ خرید آردی ثبت نشده', $issue->title);
    }

    public function test_a_recent_purchase_keeps_the_page_quiet(): void
    {
        $this->stock(5_000);
        $this->bake(2_000);

        $this->assertSame([], $this->issues());
    }

    public function test_an_old_purchase_with_baking_since_is_said(): void
    {
        $this->travel(-40)->days();
        $this->stock(20_000);
        $this->travelBack();

        $this->bake(2_000);

        $issue = $this->issues()[0] ?? null;

        $this->assertNotNull($issue);
        $this->assertStringContainsString('40 روز', $issue->title);
    }

    public function test_a_shop_that_has_not_baked_is_left_alone(): void
    {
        // مغازه‌ای که تعطیل بوده دلیلی ندارد خرید ثبت کرده باشد. سنجه
        // مصرف است، نه تقویم.
        $this->travel(-40)->days();
        $this->stock(20_000);
        $this->travelBack();

        $this->assertSame([], $this->issues());
    }

    public function test_baking_long_ago_does_not_count_as_baking_now(): void
    {
        $this->travel(-60)->days();
        $this->stock(20_000);
        $this->travelBack();

        $this->bake(2_000, daysAgo: 30);

        $this->assertSame([], $this->issues());
    }

    public function test_a_long_gap_is_a_warning_not_a_note(): void
    {
        $this->travel(-40)->days();
        $this->stock(20_000);
        $this->travelBack();

        $this->bake(2_000);

        $this->assertSame('warning', $this->issues()[0]->severity);
    }

    public function test_a_short_gap_is_only_a_note(): void
    {
        $this->travel(-15)->days();
        $this->stock(20_000);
        $this->travelBack();

        $this->bake(2_000);

        $this->assertSame('info', $this->issues()[0]->severity);
    }

    public function test_it_says_how_much_was_used_and_points_at_the_invoices(): void
    {
        $this->flour->move('in', 20_000, 'correction', $this->owner->id);
        $this->bake(4_000);

        $issue = $this->issues()[0];

        // ۴۰۰۰ کیلو ÷ ۴۰ = ۱۰۰ کیسه
        $this->assertStringContainsString('100.0 کیسه', $issue->detail);
        $this->assertSame('/admin/purchases', $issue->url);
    }

    public function test_flour_sold_over_the_counter_counts_as_use_too(): void
    {
        $this->flour->move('in', 20_000, 'correction', $this->owner->id);

        $this->travel(-1)->days();
        $this->flour->move('out', 2_000, 'flour_sale', $this->owner->id);
        $this->travelBack();

        $this->assertNotSame([], $this->issues());
    }
}
