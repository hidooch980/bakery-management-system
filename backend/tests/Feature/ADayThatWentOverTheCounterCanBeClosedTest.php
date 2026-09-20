<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\InventoryItem;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use App\Support\SellerSettlement;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * روزی که همهٔ چانه روی پیشخوان رفت.
 *
 * «نقدی» از فهرستِ چیزهایی که فروشنده روی یک فروش می‌گذارد برداشته شد،
 * به گفتهٔ مالک: «فقط در تسویه حساب فروشنده باشد».
 *
 * آن‌وقت روزِ معمولی یک بن‌بست شد. فروشنده چیزی برای وارد کردن نداشت،
 * و فرم بدون حداقل یک ردیف ذخیره نمی‌شد — سرور ۴۲۲ می‌داد و چانه
 * «pending» می‌ماند. چانه‌ای که کسی نتواند ببنددش، برای همیشه باز
 * می‌ماند.
 *
 * حالا فروشنده می‌گوید «همه‌اش روی من» و سرِ تسویه با اسکناسی که در
 * جیب دارد حلش می‌کند. مبلغ تا آن موقع روی حساب خودش است — که همان
 * جایی است که واقعاً هست.
 */
class ADayThatWentOverTheCounterCanBeClosedTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE = 3_000;

    private User $seller;

    private ChaneEntry $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman', 'bread_price' => self::PRICE]);
        Money::forgetCache();

        foreach (['flour' => 20_000, 'salt' => 2_000, 'yeast_dry' => 1_000] as $item => $kg) {
            InventoryItem::ofKey($item)->move('in', $kg, 'manual');
        }

        $maker = User::factory()->create(['is_active' => true]);
        $maker->assignRole('dough_maker');

        $gir = User::factory()->create(['is_active' => true]);
        $gir->assignRole('chane_gir');

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        Sanctum::actingAs($maker);
        $dough = $this->postJson('/api/v1/dough-entries', [
            'bag_count' => 10,
            'yeast_type' => 'dry',
        ])->assertCreated()->json('data.entry');

        Sanctum::actingAs($gir);
        $entry = $this->postJson('/api/v1/chane-entries', [
            'dough_entry_id' => $dough['id'],
            'chane_count' => 150,
            'spray_flour_kg' => 3,
        ])->assertCreated()->json('data.entry');

        $this->batch = ChaneEntry::findOrFail($entry['id']);
    }

    public function test_the_whole_batch_can_be_put_on_the_sellers_own_account(): void
    {
        Sanctum::actingAs($this->seller);

        $this->postJson('/api/v1/sales', [
            'chane_entry_id' => $this->batch->id,
            'payments' => [
                ['payment_type' => 'shortfall', 'bread_count' => 150],
            ],
        ])->assertCreated();

        // The batch is closed. Before this, a day like it could not be
        // recorded at all.
        $this->assertSame('sold', $this->batch->fresh()->status);

        $owed = SellerSettlement::outstandingFor($this->seller);

        $this->assertEqualsWithDelta(150 * self::PRICE, $owed['total'], 0.01);
        $this->assertEqualsWithDelta(150 * self::PRICE, $owed['shortfall'], 0.01);
    }

    public function test_it_is_not_counted_twice_by_the_batch_backstop(): void
    {
        // The recorder derives a shortfall from «batch less what was
        // accounted for». A named shortfall line counts towards the batch,
        // so the derived one must come out at nothing — otherwise the
        // seller answers for 300 loaves when 150 went out.
        Sanctum::actingAs($this->seller);

        $this->postJson('/api/v1/sales', [
            'chane_entry_id' => $this->batch->id,
            'payments' => [
                ['payment_type' => 'shortfall', 'bread_count' => 150],
            ],
        ])->assertCreated();

        $this->assertSame(150, (int) Sale::sum('shortfall_count'));
        $this->assertSame(1, Sale::count());
    }

    public function test_a_part_of_the_batch_still_works_the_way_it_did(): void
    {
        // Fifty on the reader, the rest on him. The half he named is a
        // real sale; the half he did not is his until he settles.
        Sanctum::actingAs($this->seller);

        $this->postJson('/api/v1/sales', [
            'chane_entry_id' => $this->batch->id,
            'payments' => [
                ['payment_type' => 'card', 'bread_count' => 50, 'amount' => 50 * self::PRICE],
                ['payment_type' => 'shortfall', 'bread_count' => 100],
            ],
        ])->assertCreated();

        $owed = SellerSettlement::outstandingFor($this->seller);

        $this->assertEqualsWithDelta(100 * self::PRICE, $owed['shortfall'], 0.01);
        $this->assertSame(100, (int) Sale::sum('shortfall_count'));
    }

    public function test_an_empty_sale_is_still_refused(): void
    {
        // «Nothing at all» is not an answer. Saying the whole batch is on
        // him is one, and it has to be said.
        Sanctum::actingAs($this->seller);

        $this->postJson('/api/v1/sales', [
            'chane_entry_id' => $this->batch->id,
            'payments' => [],
        ])->assertStatus(422);

        $this->assertSame('pending', $this->batch->fresh()->status);
    }
}
