<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\FlourAllocation;
use App\Models\Holiday;
use App\Models\User;
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
}
