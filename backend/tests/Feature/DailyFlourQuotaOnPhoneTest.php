<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\FlourAllocation;
use App\Models\Holiday;
use App\Models\InventoryItem;
use App\Models\Sale;
use App\Models\User;
use App\Support\CurrentBakery;
use App\Support\FlourQuota;
use App\Support\Jalali;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyFlourQuotaOnPhoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first()->update(['flour_bag_weight_kg' => 40]);
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');
        $this->actingAs($seller, 'sanctum');
        $this->travelTo(Jalali::parse('1405/05/06')->setTime(12, 0));
    }

    private function allocation(float $carryover = 0): FlourAllocation
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد ۱۴۰۵',
            'total_bags' => 300,
            'carryover_bags' => $carryover,
        ]);
        $allocation->syncPeriods();

        return $allocation;
    }

    public function test_فروشنده_سهم_روزانه_دوره_را_به_کیسه_می‌خواند(): void
    {
        $this->allocation();
        $response = $this->getJson('/api/v1/flour-allocations/current')->assertOk();
        $response->assertJsonPath('data.periods.0.working_days', 10)
            ->assertJsonPath('data.periods.0.holiday_days', 0);
        $this->assertEquals(10, $response->json('data.periods.0.daily_pace_bags'));
    }

    public function test_دوره_روزانه_فقط_مصرف_و_فروش_همان_روز_را_دارد_و_چانه_تکرار_نمی‌شود(): void
    {
        $this->allocation(90);
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', 1000, 'purchase');
        $this->travelTo(Jalali::parse('1405/05/05')->setTime(12, 0));
        $flour->move('out', 80, 'production');
        $this->travelTo(Jalali::parse('1405/05/06')->setTime(12, 0));
        $flour->move('out', 40, 'production');
        // ثبت مستقیم برای جدا ماندن آزمون گزارش از محاسبهٔ مواد تولید.
        $dough = DoughEntry::withoutEvents(fn () => DoughEntry::forceCreate(['bakery_id' => CurrentBakery::id(), 'user_id' => auth()->id(), 'bag_count' => 1]));
        $chane = ChaneEntry::withoutEvents(fn () => ChaneEntry::forceCreate([
            'bakery_id' => CurrentBakery::id(),
            'user_id' => auth()->id(), 'dough_entry_id' => $dough->id,
            'chane_count' => 100, 'normal_weight_kg' => 40, 'nanino_weight_kg' => 0, 'spray_flour_kg' => 0,
        ]));
        foreach (['card', 'card', 'cash'] as $type) {
            Sale::withoutEvents(fn () => Sale::forceCreate([
                'bakery_id' => CurrentBakery::id(),
                'user_id' => auth()->id(), 'chane_entry_id' => $chane->id,
                'payment_type' => $type, 'bread_count' => 20, 'amount' => 10000,
            ]));
        }
        $old = Sale::withoutEvents(fn () => Sale::forceCreate([
            'bakery_id' => CurrentBakery::id(),
            'user_id' => auth()->id(), 'chane_entry_id' => $chane->id,
            'payment_type' => 'card', 'bread_count' => 500, 'amount' => 10000,
        ]));
        $old->forceFill(['created_at' => now()->subDay()])->saveQuietly();
        $response = $this->getJson('/api/v1/flour-allocations/current')->assertOk();
        $response->assertJsonPath('data.daily_period.date', today()->toDateString())
            ->assertJsonPath('data.daily_period.card_bread_count', 40)
            ->assertJsonPath('data.daily_period.chane_count', 100);
        $this->assertEquals(10, $response->json('data.daily_period.allocated_bags'));
        $this->assertEquals(1, $response->json('data.daily_period.used_bags'));
        $this->assertEquals(9, $response->json('data.daily_period.remaining_bags'));
        $this->assertEquals(3, $response->json('data.periods.0.used_bags'));
        $this->assertDatabaseCount('flour_allocation_periods', 3);
    }

    public function test_روز_تعطیل_سهم_صفر_دارد_ولی_مصرف_واقعی_آن_پنهان_نمی‌شود(): void
    {
        $this->allocation();
        Holiday::create(['date' => today(), 'title' => 'تعطیلی', 'type' => 'shop']);
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', 100, 'purchase');
        $flour->move('out', 40, 'production');
        $response = $this->getJson('/api/v1/flour-allocations/current')->assertOk();
        $response->assertJsonPath('data.daily_period.is_holiday', true);
        $this->assertEquals(0, $response->json('data.daily_period.allocated_bags'));
        $this->assertEquals(-1, $response->json('data.daily_period.remaining_bags'));
    }

    public function test_در_مرز_دوره_روزانه_از_دوره_جدید_و_تاریخ_سرور_خوانده_می‌شود(): void
    {
        $this->allocation();
        Holiday::create(['date' => Jalali::parse('1405/05/20'), 'title' => 'تعطیلی', 'type' => 'shop']);
        $this->travelTo(Jalali::parse('1405/05/14')->setTime(23, 59));
        $this->assertEquals(10, $this->getJson('/api/v1/flour-allocations/current')->json('data.daily_period.allocated_bags'));
        $this->travelTo(Jalali::parse('1405/05/15')->setTime(0, 1));
        $response = $this->getJson('/api/v1/flour-allocations/current')->assertOk();
        $response->assertJsonPath('data.daily_period.date', today()->toDateString());
        $this->assertEquals(11.11, $response->json('data.daily_period.allocated_bags'));
    }

    public function test_تعطیلی_ثبت‌شده_از_مخرج_سهم_روزانه_کم_می‌شود(): void
    {
        $this->allocation();
        Holiday::create([
            'date' => Jalali::parse('1405/05/10'),
            'title' => 'تعطیلی نانوایی',
            'type' => 'shop',
        ]);
        $response = $this->getJson('/api/v1/flour-allocations/current')->assertOk();
        $response->assertJsonPath('data.periods.0.working_days', 9)
            ->assertJsonPath('data.periods.0.holiday_days', 1);
        $this->assertEquals(11.11, $response->json('data.periods.0.daily_pace_bags'));
    }

    public function test_آرد_سنوات_به_سهم_روزانه_دوره_اضافه_نمی‌شود(): void
    {
        $this->allocation(90);
        $response = $this->getJson('/api/v1/flour-allocations/current')->assertOk();
        $this->assertEquals(10, $response->json('data.periods.0.daily_pace_bags'));
        $this->assertEquals(390, $response->json('data.available_bags'));
    }

    public function test_میانگین_ماه_از_کل_روزهای_کاری_است_نه_میانگین_سه_دوره(): void
    {
        $allocation = $this->allocation();
        $days = $allocation->periods()->get()->sum('working_days');
        $response = $this->getJson('/api/v1/flour-allocations/current')->assertOk();
        $this->assertEquals($days, $response->json('data.whole_period.working_days'));
        $this->assertEquals(round(300 / $days, 2), $response->json('data.whole_period.daily_pace_bags'));
        $this->assertEquals(300, $response->json('data.whole_period.allocated_bags'));
    }

    public function test_دوره_کاملاً_تعطیل_تقسیم_بر_صفر_ندارد(): void
    {
        $this->allocation();
        for ($day = 5; $day <= 14; $day++) {
            Holiday::create([
                'date' => Jalali::parse(sprintf('1405/05/%02d', $day)),
                'title' => 'تعطیلی نانوایی',
                'type' => 'shop',
            ]);
        }
        $response = $this->getJson('/api/v1/flour-allocations/current')->assertOk();
        $response->assertJsonPath('data.periods.0.working_days', 0);
        $this->assertEquals(0, $response->json('data.periods.0.daily_pace_bags'));
    }

    public function test_انتقال_دستی_از_مانده_کم_نمی‌شود_و_در_سهم_روزانه_هم_پخش_نمی‌شود(): void
    {
        $this->allocation(90);
        // ۹۰ کیسه ماندهٔ اصلاح‌شده + ۱۰۰ کیسهٔ دورهٔ اول؛ دوره‌های آینده هنوز اعتبار ندارند.
        $response = $this->getJson('/api/v1/flour-allocations/current')->assertOk();
        $this->assertEquals(190, $response->json('data.carried_balance.remaining_bags'));
        $this->assertEquals(10, $response->json('data.periods.0.daily_pace_bags'));
    }

    public function test_ماندهٔ_واقعی_ماه_قبل_با_ثبت_دستی_آن_دوباره_جمع_نمی‌شود(): void
    {
        $prior = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/04/01'), 'month_label' => 'تیر', 'total_bags' => 90,
        ]);
        $prior->syncPeriods();
        $this->allocation(90);
        $response = $this->getJson('/api/v1/flour-allocations/current')->assertOk();
        $this->assertEquals(190, $response->json('data.carried_balance.remaining_bags'));
        $this->travelTo(Jalali::parse('1405/06/06')->setTime(12, 0));
        // هر سه دورهٔ مرداد آغاز شده‌اند و مانده از بین نرفته است.
        $this->assertEquals(390, FlourQuota::remainingBags());
    }
}
