<?php

namespace Tests\Feature;

use App\Filament\Pages\SellerDailyDebtReport;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\FlourAllocation;
use App\Models\Holiday;
use App\Models\InventoryItem;
use App\Models\Sale;
use App\Models\SellerAccountCredit;
use App\Models\SellerSettlementRecord;
use App\Models\User;
use App\Support\CurrentBakery;
use App\Support\Jalali;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SellerDailyReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private ChaneEntry $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        $this->travelTo(Jalali::parse('1405/05/06')->setTime(12, 0));
        Bakery::first()->update(['flour_bag_weight_kg' => 40, 'nanino_per_bag' => 64, 'currency' => 'toman']);
        Money::forgetCache();
        CurrentBakery::forget();
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');
        $this->actingAs($this->admin, 'sanctum');
        foreach ([InventoryItem::FLOUR, InventoryItem::SALT, InventoryItem::YEAST_DRY] as $key) {
            InventoryItem::ofKey($key)->move('in', 10000, 'purchase');
        }
        $dough = DoughEntry::create(['user_id' => $this->seller->id, 'bag_count' => 4]);
        $this->batch = ChaneEntry::create([
            'dough_entry_id' => $dough->id, 'user_id' => $this->seller->id,
            'chane_count' => 400, 'normal_weight_kg' => 340, 'nanino_weight_kg' => 0, 'spray_flour_kg' => 0,
        ]);
    }

    private function sale(string $type, int $bread, float $amount, ?User $seller = null): Sale
    {
        return Sale::create([
            'user_id' => ($seller ?? $this->seller)->id, 'chane_entry_id' => $this->batch->id,
            'payment_type' => $type, 'bread_count' => $bread, 'amount' => $amount,
        ]);
    }

    private function url(?User $seller = null): string
    {
        $id = ($seller ?? $this->seller)->id;
        $date = today()->toDateString();

        return "/api/v1/seller-accounts/{$id}/daily-report?from={$date}&to={$date}";
    }

    private function quota(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'), 'month_label' => 'مرداد ۱۴۰۵',
            'total_bags' => 300, 'carryover_bags' => 90,
        ]);
        $allocation->syncPeriods();
    }

    public function test_چانه_تکراری_نمی‌شود_و_کارتخوان_فروشنده_از_کل_نانوایی_جداست(): void
    {
        $this->quota();
        $this->sale('card', 100, 500000);
        $this->sale('cash', 50, 250000);
        $this->sale('home', 10, 0);
        $this->sale('waste', 5, 0);
        $other = User::factory()->create();
        $other->assignRole('seller');
        $this->sale('card', 40, 200000, $other);
        $response = $this->getJson($this->url())->assertOk();
        $response->assertJsonPath('data.days.0.bakery_chane_count', 400)
            ->assertJsonPath('data.days.0.seller_card_bread_count', 100)
            ->assertJsonPath('data.days.0.bakery_card_bread_count', 140)
            ->assertJsonPath('data.days.0.quota_card_target', 640)
            ->assertJsonPath('data.days.0.home_bread_count', 10)
            ->assertJsonPath('data.days.0.waste_bread_count', 5);
        $this->assertEquals(10, $response->json('data.days.0.quota_bags'));
        $this->assertEquals(250000, $response->json('data.summary.still_owed'));
        $this->assertCount(4, $response->json('data.days.0.lines'));
        $this->assertStringContainsString('داخل اپ', $response->json('data.card_source'));
    }

    public function test_بدهی_قدیمی_می‌ماند_و_پرداخت_و_نسیه_دوباره_از_حساب_کم_نمی‌شوند(): void
    {
        $old = $this->sale('cash', 20, 100000);
        $old->forceFill(['created_at' => now()->subDays(35)])->saveQuietly();
        $this->sale('credit', 10, 50000);
        SellerAccountCredit::create(['user_id' => $this->seller->id, 'amount' => 40000]);
        SellerSettlementRecord::create([
            'user_id' => $this->seller->id, 'settled_by' => $this->admin->id,
            'kind' => 'payment', 'amount' => 40000, 'paid_cash' => 40000, 'paid_card' => 0,
        ]);
        SellerSettlementRecord::create([
            'user_id' => $this->seller->id, 'settled_by' => $this->admin->id,
            'kind' => 'payment', 'amount' => 10000, 'paid_cash' => 10000, 'paid_card' => 0,
            'reversed_at' => now(),
        ]);
        $response = $this->getJson($this->url())->assertOk();
        $response->assertJsonPath('data.summary.still_owed', 60000);
        $this->assertSame(Money::format(50000), $response->json('data.summary.credit_formatted'));
        $this->assertSame(Money::format(40000), $response->json('data.days.0.handed_over_formatted'));
        $this->assertContains($old->created_at->toDateString(), array_column($response->json('data.open_days'), 'date'));
        $this->assertCount(2, $response->json('data.handovers'));
    }

    public function test_سهمیه_ناموجود_صفر_ساختگی_نیست_و_تعطیلی_سهم_روزانه_ندارد(): void
    {
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.days.0.quota_bags', null);
        $this->quota();
        Holiday::create(['date' => today(), 'title' => 'تعطیلی', 'type' => 'shop']);
        $response = $this->getJson($this->url())->assertOk()->assertJsonPath('data.days.0.is_holiday', true);
        $this->assertEquals(0, $response->json('data.days.0.quota_bags'));
        $response->assertJsonPath('data.days.0.quota_card_target', 0);
    }

    public function test_بازه_نامعتبر_و_خیلی_طولانی_رد_می‌شود(): void
    {
        $base = "/api/v1/seller-accounts/{$this->seller->id}/daily-report";
        $this->getJson($base.'?from=bad')->assertUnprocessable();
        $this->getJson($base.'?from=2026-08-02&to=2026-08-01')->assertUnprocessable();
        $this->getJson($base.'?from=2026-01-01&to=2026-12-31')->assertUnprocessable();
    }

    public function test_فروشنده_و_نانوایی_دیگر_به_ریز_حساب_دسترسی_ندارند(): void
    {
        $this->actingAs($this->seller, 'sanctum')->getJson($this->url())->assertForbidden();
        $this->actingAs($this->admin, 'sanctum');
        $other = Bakery::create(['name' => 'نانوایی دیگر']);
        $foreign = User::factory()->create(['bakery_id' => $other->id]);
        $foreign->assignRole('seller');
        $this->getJson($this->url($foreign))->assertNotFound();
    }

    public function test_گزارش_در_پنل_هم_نمایش_داده_می‌شود(): void
    {
        $this->actingAs($this->admin, 'web');
        Livewire::test(SellerDailyDebtReport::class)
            ->set('sellerId', (string) $this->seller->id)
            ->assertSee('سهمیه ثبت نشده است')
            ->assertSee('ماندهٔ بدهی فروشنده')
            ->assertSee('کارتخوان فروشنده', false);
    }

    public function test_ریز_انبار_وزن_و_کیسه_را_با_اندازه_تنظیم‌شده_می‌فرستد(): void
    {
        Bakery::first()->update(['flour_bag_weight_kg' => 50]);
        CurrentBakery::forget();
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 125, 'purchase');
        $rows = $this->getJson('/api/v1/inventory/movements?item=flour')->assertOk()->json('data.data');
        $row = collect($rows)->firstWhere('quantity', 125);
        $this->assertNotNull($row);
        $this->assertEquals(50, $row['bag_weight_kg']);
        $this->assertEquals(2.5, $row['quantity_bags']);
        $this->actingAs($this->seller, 'sanctum');
        $this->getJson('/api/v1/inventory/movements?item=flour')->assertOk();
        $day = $this->getJson('/api/v1/inventory/flour/day')->assertOk()->json('data');
        $movement = collect($day['movements'])->firstWhere('quantity_kg', 125);
        $this->assertEquals(2.5, $movement['bags']);
        $this->actingAs($this->admin, 'sanctum');
        InventoryItem::ofKey(InventoryItem::SALT)->update(['bag_weight_kg' => 0]);
        $salt = $this->getJson('/api/v1/inventory/movements?item=salt')->assertOk()->json('data.data.0');
        $this->assertNull($salt['quantity_bags']);
    }
}
