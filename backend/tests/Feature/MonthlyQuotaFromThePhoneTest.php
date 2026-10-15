<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\DieselAllocation;
use App\Models\FlourAllocation;
use App\Models\User;
use App\Support\Jalali;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyQuotaFromThePhoneTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first()->update(['diesel_litres_per_bag' => 6.5]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_سهمیه_ماه_قبل_مانع_ساخت_گازوئیل_ماه_انتخابی_نمی‌شود(): void
    {
        $previous = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/06/01'),
            'month_label' => 'شهریور ۱۴۰۵',
            'total_bags' => 100,
        ]);
        $previous->syncPeriods();
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/flour-allocations', [
                'month_start' => '1405/07/01',
                'total_bags' => 300,
                'carryover_bags' => 20,
            ])->assertCreated();

        $this->assertSame(2, DieselAllocation::count());
        $this->assertEquals(650, (float) DieselAllocation::whereDate(
            'month_start', Jalali::parse('1405/06/01')->toDateString()
        )->firstOrFail()->total_litres);
        $this->assertEquals(1950, (float) DieselAllocation::whereDate(
            'month_start', Jalali::parse('1405/07/01')->toDateString()
        )->firstOrFail()->total_litres);
        $allocation = FlourAllocation::findOrFail($response->json('data.id'));
        $this->assertSame(3, $allocation->periods()->count());
        $this->assertEquals((float) $allocation->total_kg, (float) $allocation->periods()->sum('allocated_kg'));
        $this->assertEquals(20, (float) $allocation->carryover_bags);
    }

    public function test_سهمیه_موجود_همان_ماه_دوباره_ساخته_یا_بازنویسی_نمی‌شود(): void
    {
        $diesel = DieselAllocation::create([
            'month_start' => Jalali::parse('1405/07/05'),
            'total_litres' => 1700,
        ]);
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/flour-allocations', [
                'month_start' => '1405/07/01', 'total_bags' => 300,
            ])->assertCreated();
        $this->assertSame(1, DieselAllocation::count());
        $this->assertEquals(1700, (float) $diesel->fresh()->total_litres);
    }

    public function test_تکرار_ثبت_سهمیه_ماه_با_پیام_تکراری_پاسخ_داده_می‌شود(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/flour-allocations', [
                'month_start' => '1405/07/01', 'total_bags' => 300,
            ])->assertCreated();
        $this->postJson('/api/v1/flour-allocations', [
            'month_start' => '1405/07/01', 'total_bags' => 500,
        ])->assertStatus(409);
        $this->assertSame(1, FlourAllocation::count());
        $this->assertSame(1, DieselAllocation::count());
        $this->assertEquals(300, (float) FlourAllocation::first()->total_bags);
        $this->assertEquals(1950, (float) DieselAllocation::first()->total_litres);
    }

    public function test_کارمند_اجازه_ثبت_سهمیه_ماهانه_ندارد(): void
    {
        $worker = User::factory()->create(['is_active' => true]);
        $worker->assignRole('shater');
        $this->actingAs($worker, 'sanctum')
            ->postJson('/api/v1/flour-allocations', [
                'month_start' => '1405/07/01', 'total_bags' => 300,
            ])->assertForbidden();
        $this->assertSame(0, FlourAllocation::count());
    }
}
