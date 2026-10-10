<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * «گردش روزانه آرد برای فروشنده نمایش داده بشه» — یک روز انبار آرد، فقط
 * به کیسه، فقط خواندنی.
 */
class TheSellerSeesTheDaysFlourTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first()->update(['flour_bag_weight_kg' => 40]);

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function at(string $when, callable $do): void
    {
        Carbon::setTestNow(Carbon::parse($when));
        $do();
        Carbon::setTestNow();
    }

    private function flour(): InventoryItem
    {
        return InventoryItem::ofKey(InventoryItem::FLOUR);
    }

    public function test_one_day_in_sacks_opening_in_out_closing_and_each_movement(): void
    {
        $this->at('2026-10-09 10:00', fn () => $this->flour()->move('in', 400, 'purchase'));

        $kent = Customer::create(['name' => 'نانوایی کنت', 'type' => Customer::PARTNER_TYPE, 'is_active' => true]);

        $this->at('2026-10-10 07:15', fn () => $this->flour()->move('in', 200, 'purchase'));
        $this->at('2026-10-10 08:30', fn () => $this->flour()->move('out', 120, 'production'));
        $this->at('2026-10-10 09:00', fn () => $this->flour()->move('out', 20, 'spray'));
        $this->at('2026-10-10 11:45', fn () => ConsignmentFlour::create([
            'customer_id' => $kent->id, 'direction' => 'lent', 'bags' => 2, 'occurred_on' => '2026-10-10',
        ]));
        $this->at('2026-10-10 12:00', fn () => ConsignmentFlour::create([
            'customer_id' => $kent->id, 'direction' => 'borrowed', 'bags' => 1, 'occurred_on' => '2026-10-10',
        ]));
        $this->at('2026-10-11 09:00', fn () => $this->flour()->move('out', 40, 'flour_sale'));

        Carbon::setTestNow('2026-10-11 13:00');

        $day = $this->actingAs($this->seller)
            ->getJson('/api/v1/inventory/flour/day?date=1405/07/18')
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-10-10', $day['date']);
        $this->assertEqualsWithDelta(10.0, $day['opening_bags'], 0.001);
        $this->assertEqualsWithDelta(6.0, $day['in_bags'], 0.001);   // ۵ خرید + ۱ از همکار
        $this->assertEqualsWithDelta(5.5, $day['out_bags'], 0.001);  // ۳ خمیر + ۰.۵ پاششی + ۲ به همکار
        $this->assertEqualsWithDelta(10.5, $day['closing_bags'], 0.001);

        $this->assertSame(['purchase', 'consignment_in'], array_column($day['in'], 'reason'));
        $this->assertSame(['production', 'consignment_out', 'spray'], array_column($day['out'], 'reason'));

        $this->assertCount(5, $day['movements']);
        $this->assertSame('07:15', $day['movements'][0]['time']);
        $this->assertSame('خرید', $day['movements'][0]['label']);
        $this->assertEqualsWithDelta(5.0, $day['movements'][0]['bags'], 0.001);
        $this->assertSame('2026-10-11', $day['next_date']);
        $this->assertFalse($day['is_today']);

        // هیچ کیلویی در پاسخ نیست، جز وزن کیسه برای اطلاع.
        $this->assertStringNotContainsString('_kg"', str_replace('"bag_weight_kg"', '', json_encode($day)));
    }

    public function test_today_by_default_and_tomorrow_is_not_offered(): void
    {
        Carbon::setTestNow('2026-10-11 13:00');
        $this->flour()->move('in', 80, 'purchase');

        $day = $this->actingAs($this->seller)->getJson('/api/v1/inventory/flour/day')->assertOk()->json('data');

        $this->assertSame('2026-10-11', $day['date']);
        $this->assertTrue($day['is_today']);
        $this->assertNull($day['next_date']);
        $this->assertSame('2026-10-10', $day['previous_date']);
        $this->assertEqualsWithDelta(2.0, $day['closing_bags'], 0.001);
    }

    public function test_a_role_without_the_warehouse_cannot_read_it(): void
    {
        $baker = User::factory()->create(['is_active' => true]);
        $baker->assignRole('shater');

        $this->actingAs($baker)->getJson('/api/v1/inventory/flour/day')->assertForbidden();
    }

    public function test_it_is_read_only(): void
    {
        $this->actingAs($this->seller)->postJson('/api/v1/inventory/flour/day')->assertStatus(405);
        $this->assertSame(0, InventoryMovement::count());
    }
}
