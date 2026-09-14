<?php

namespace Tests\Feature;

use App\Filament\Resources\StockCountResource;
use App\Filament\Resources\StockCountResource\Pages\CreateStockCount;
use App\Models\Bakery;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\StockCount;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * انبار شمرده می‌شود و دفتر خبردار.
 *
 * ۱۴۰۵/۰۶/۲۳ موجودی آرد دو بار با دست اصلاح شد، هر بار با یک دستور روی
 * سرور. دفتر آرد نشان داد بار اول نبوده: پنج اصلاح پیش از آن هم بود، هر
 * پنج تا رو به بالا، جمعاً ۲۳۶ کیسه. کاری که پنج بار با دست انجام شده،
 * صفحه می‌خواهد.
 *
 * قرینهٔ شمارش صندوق و عمداً هم‌شکل: شمارش به‌خودی‌خود چیزی را جابه‌جا
 * نمی‌کند، اصلاح تصمیم جداگانه‌ای است که به همان شمارش وصل می‌شود، و
 * عددی که نوشته شد دیگر ویرایش نمی‌شود.
 */
class TheStoreIsCountedTest extends TestCase
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
        $this->flour->move('in', 4_000, 'purchase', $this->owner->id);
    }

    private function countStore(array $body = [])
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/stock-counts', array_merge([
                'item' => 'flour',
                // به کیسه، همان‌طور که روی صفحه گفته می‌شود.
                'counted' => 100,
            ], $body));
    }

    private function balance(): float
    {
        return round((float) InventoryItem::ofKey(InventoryItem::FLOUR)->fresh()->balance, 3);
    }

    public function test_a_store_that_agrees_is_recorded_as_agreeing(): void
    {
        $this->countStore()
            ->assertCreated()
            ->assertJsonPath('data.difference_label', 'می‌خواند')
            ->assertJsonPath('data.is_exact', true);
    }

    public function test_a_short_store_is_named_as_short(): void
    {
        $this->countStore(['counted' => 90])
            ->assertCreated()
            ->assertJsonPath('data.difference_label', 'کسری')
            ->assertJsonPath('data.difference.bags', 10);
    }

    public function test_more_on_the_shelf_than_the_books_know_is_named_as_extra(): void
    {
        $this->countStore(['counted' => 113])
            ->assertCreated()
            ->assertJsonPath('data.difference_label', 'اضافه')
            ->assertJsonPath('data.difference.bags', 13);
    }

    public function test_counting_alone_moves_no_stock(): void
    {
        $this->countStore(['counted' => 113])->assertCreated();

        $this->assertEqualsWithDelta(4_000, $this->balance(), 0.01);
    }

    public function test_the_books_are_corrected_only_when_asked(): void
    {
        $this->countStore(['counted' => 113, 'adjust' => true])->assertCreated();

        $this->assertEqualsWithDelta(4_520, $this->balance(), 0.01);
    }

    public function test_a_shortfall_is_corrected_downwards(): void
    {
        $this->countStore(['counted' => 90, 'adjust' => true])->assertCreated();

        $this->assertEqualsWithDelta(3_600, $this->balance(), 0.01);
    }

    public function test_the_correction_is_filed_as_a_stocktake_not_a_hand_entry(): void
    {
        // «اصلاح موجودی» بی‌نام است. یک خط که می‌گوید چیست، می‌شود
        // دربارهٔ آن بحث کرد؛ خطی که چیزی نمی‌گوید نه.
        $this->countStore(['counted' => 113, 'adjust' => true])->assertCreated();

        $this->assertSame('stocktake', InventoryMovement::latest('id')->first()->reason);
    }

    public function test_the_correction_points_back_at_the_count_that_caused_it(): void
    {
        $id = $this->countStore(['counted' => 113, 'adjust' => true])
            ->assertCreated()->json('data.id');

        $this->assertNotNull(StockCount::find($id)->adjustment);
    }

    public function test_a_store_that_already_agrees_writes_no_correction(): void
    {
        $before = InventoryMovement::count();

        $this->countStore(['adjust' => true])->assertCreated();

        $this->assertSame($before, InventoryMovement::count());
    }

    public function test_the_expected_figure_is_kept_as_it_was_at_the_time(): void
    {
        // شمارش، حرفی دربارهٔ یک لحظه است. محاسبهٔ دوبارهٔ آن در برابر
        // دفتری که جلو رفته، هر بار که صفحه باز شود تاریخ را بازنویسی
        // می‌کند.
        $this->countStore(['counted' => 113])->assertCreated();

        $this->flour->move('out', 1_000, 'production', $this->owner->id);

        $this->assertEqualsWithDelta(
            4_000,
            (float) StockCount::first()->expected_quantity,
            0.01,
        );
    }

    public function test_the_page_says_what_to_count_against_and_when_it_was_last_done(): void
    {
        $this->travel(-3)->days();
        $this->countStore(['counted' => 90])->assertCreated();
        $this->travelBack();

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/stock-counts?item=flour')
            ->assertOk()
            ->assertJsonPath('data.expected.bags', 100)
            ->assertJsonPath('data.days_since_count', 3)
            ->assertJsonPath('data.first_count', false)
            ->assertJsonPath('data.counts.0.difference_label', 'کسری');
    }

    public function test_a_store_never_counted_says_so(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/stock-counts?item=flour')
            ->assertOk()
            ->assertJsonPath('data.first_count', true)
            ->assertJsonPath('data.days_since_count', null);
    }

    public function test_an_unknown_item_is_refused_rather_than_invented(): void
    {
        // `ofKey` هر کلیدی را می‌سازد. یک کلید دلخواه نباید ردیفی در انبار
        // درست کند که هیچ‌کس نخواسته بود.
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/stock-counts?item=gold')
            ->assertStatus(422);

        $this->assertNull(InventoryItem::where('key', 'gold')->first());
    }

    public function test_the_count_is_stored_in_the_base_unit_not_in_sacks(): void
    {
        // وزن کیسه در تنظیمات عوض می‌شود. شمارشی که به کیسه ذخیره شده
        // باشد، آن روز معنی تازه‌ای پیدا می‌کند.
        $this->countStore(['counted' => 113])->assertCreated();

        $this->assertEqualsWithDelta(
            4_520,
            (float) StockCount::first()->counted_quantity,
            0.01,
        );
    }

    public function test_a_seller_may_count_and_say_what_they_saw(): void
    {
        // فروشنده‌ها `manage-inventory` دارند چون محموله ثبت می‌کنند، و
        // شمارش، سند است: نوشته می‌شود، ویرایش نمی‌شود، و اسم شمارنده
        // رویش می‌ماند.
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/stock-counts', ['item' => 'flour', 'counted' => 90])
            ->assertCreated()
            ->assertJsonPath('data.difference_label', 'کسری')
            ->assertJsonPath('data.counted_by', $seller->name);
    }

    public function test_a_seller_cannot_rewrite_the_books_from_their_count(): void
    {
        // اصلاح موجودی، عددی را عوض می‌کند که سفارش آرد و بهای تمام‌شدهٔ
        // نان از رویش بسته می‌شود. آن تصمیم، تصمیم صاحب مغازه است.
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/stock-counts', [
                'item' => 'flour',
                'counted' => 90,
                'adjust' => true,
            ])
            ->assertForbidden();
    }

    public function test_that_refusal_writes_no_count_and_moves_no_stock(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/stock-counts', [
                'item' => 'flour',
                'counted' => 90,
                'adjust' => true,
            ])
            ->assertForbidden();

        $this->assertSame(0, StockCount::count());
        $this->assertEqualsWithDelta(4_000, $this->balance(), 0.01);
    }

    // ------------------------------------------------------------ پنل

    private function openPanel(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_panel_records_a_count_and_leaves_the_store_alone(): void
    {
        $this->openPanel();

        Livewire::test(CreateStockCount::class)
            ->fillForm([
                'inventory_item_id' => $this->flour->getKey(),
                'counted' => 113,
                'adjust' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertEqualsWithDelta(4_000, $this->balance(), 0.01);
        $this->assertEqualsWithDelta(
            4_520,
            (float) StockCount::first()->counted_quantity,
            0.01,
        );
    }

    public function test_the_panel_corrects_the_books_when_the_toggle_is_on(): void
    {
        $this->openPanel();

        Livewire::test(CreateStockCount::class)
            ->fillForm([
                'inventory_item_id' => $this->flour->getKey(),
                'counted' => 113,
                'adjust' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertEqualsWithDelta(4_520, $this->balance(), 0.01);
        $this->assertSame('stocktake', InventoryMovement::latest('id')->first()->reason);
    }

    public function test_a_count_is_never_edited(): void
    {
        // عددی که بعداً بشود به توافق اصلاحش کرد، سند چیزی نیست.
        $this->assertFalse(
            StockCountResource::canEdit(new StockCount),
        );
    }
}
