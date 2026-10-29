<?php

namespace Tests\Feature;

use App\Filament\Pages\DieselMonthlyReport;
use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\DieselAllocation;
use App\Models\DieselDelivery;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\CurrentBakery;
use App\Support\DieselLedger;
use App\Support\Jalali;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DieselCarryoverTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        $this->travelTo(Jalali::parse('1405/08/10')->setTime(12, 0));
        Bakery::first()->update(['diesel_litres_per_bag' => 5]);
        CurrentBakery::forget();
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin, 'sanctum');
        foreach ([InventoryItem::FLOUR, InventoryItem::SALT, InventoryItem::YEAST_DRY] as $key) {
            InventoryItem::ofKey($key)->move('in', 10000, 'purchase');
        }
    }

    private function quota(string $month, float $total, float $rate = 5, float $manual = 0): DieselAllocation
    {
        return DieselAllocation::create(['month_start' => Jalali::parse($month.'/01'),
            'total_litres' => $total, 'litres_per_bag' => $rate, 'carryover_litres' => $manual]);
    }

    private function delivery(string $day, float $litres): DieselDelivery
    {
        return DieselDelivery::create(['received_on' => Jalali::parse($day), 'litres' => $litres, 'user_id' => $this->admin->id]);
    }

    private function bake(string $day, float $bags): void
    {
        $dough = DoughEntry::create(['user_id' => $this->admin->id, 'bag_count' => $bags]);
        $dough->forceFill(['created_at' => Jalali::parse($day)->setTime(8, 30)])->saveQuietly();
    }

    public function test_مانده_سهمیه_و_باک_در_سه_ماه_پشت_سر_هم_منتقل_می‌شوند(): void
    {
        $this->quota('1405/06', 1000);
        $this->quota('1405/07', 500);
        $current = $this->quota('1405/08', 400);
        $this->delivery('1405/06/10', 600);
        $this->delivery('1405/07/10', 300);
        $this->delivery('1405/08/06', 100);
        $this->bake('1405/06/10', 20);
        $this->bake('1405/07/10', 30);
        $this->bake('1405/08/06', 10);
        $first = DieselLedger::month('1405/06');
        $second = DieselLedger::month('1405/07');
        $third = DieselLedger::month('1405/08');
        $this->assertEquals($first['quota_closing_litres'], $second['quota_opening_litres']);
        $this->assertEquals($second['quota_closing_litres'], $third['quota_opening_litres']);
        $this->assertEquals($first['tank_closing_litres'], $second['tank_opening_litres']);
        $this->assertEquals($second['tank_closing_litres'], $third['tank_opening_litres']);
        $this->assertEquals(900, $third['quota_closing_litres']);
        $this->assertEquals(700, $third['tank_closing_litres']);
        $this->assertEquals(1000, $current->available_litres);
        $this->assertEquals(900, $current->remaining_litres);
        $this->assertEquals(700, $current->in_tank_litres);
        $this->assertCount(2, $third['lines']);
        $this->getJson('/api/v1/diesel/quota')->assertOk()->assertJsonPath('data.allocation.remaining_litres', 900)
            ->assertJsonPath('data.allocation.in_tank_litres', 700);
    }

    public function test_روز_چهارم_و_پنجم_در_ماه_درست_هستند_و_گردش_آینده_وارد_ماه_قبل_نمی‌شود(): void
    {
        $this->quota('1405/07', 1000);
        $this->quota('1405/08', 500);
        $this->delivery('1405/08/04', 40);
        $this->delivery('1405/08/05', 50);
        $this->delivery('1405/09/05', 90);
        $past = DieselLedger::month('1405/07');
        $current = DieselLedger::month('1405/08');
        $this->assertEquals(40, $past['delivered_litres']);
        $this->assertEquals(50, $current['delivered_litres']);
        $this->assertEquals(960, $current['quota_opening_litres']);
        $this->assertEquals(40, $current['tank_opening_litres']);
    }

    public function test_نرخ_تاریخی_پخت_با_نرخ_ماه_جدید_عوض_نمی‌شود(): void
    {
        $this->quota('1405/07', 1000, 5);
        $this->quota('1405/08', 1000, 7);
        $this->delivery('1405/07/10', 100);
        $this->bake('1405/07/10', 10);
        $this->bake('1405/08/10', 2);
        Bakery::first()->update(['diesel_litres_per_bag' => 99]);
        $report = DieselLedger::month('1405/08');
        $this->assertEquals(50, $report['tank_opening_litres']);
        $this->assertEquals(14, $report['consumed_litres']);
        $this->assertEquals(36, $report['tank_closing_litres']);
        $this->assertFalse($report['uses_default_rate']);
    }

    public function test_انتقال_دستی_قدیمی_دوباره_شمرده_نمی‌شود_و_در_ماه_بعد_ادامه_دارد(): void
    {
        $this->quota('1405/06', 1000);
        $this->delivery('1405/06/10', 600);
        $this->quota('1405/07', 500, 5, 400);
        $current = $this->quota('1405/08', 100);
        $this->delivery('1405/07/10', 200);
        $this->assertEquals(400, DieselLedger::month('1405/07')['quota_opening_litres']);
        $this->assertEquals(700, DieselLedger::month('1405/08')['quota_opening_litres']);
        $this->assertEquals(800, $current->available_litres);
    }

    public function test_کسری_و_ماه_بدون_سهمیه_در_انتقال_صفر_نمی‌شوند(): void
    {
        $this->quota('1405/06', 100);
        $this->delivery('1405/06/10', 150);
        $this->bake('1405/06/10', 40);
        $report = DieselLedger::month('1405/07');
        $this->assertFalse($report['quota_recorded']);
        $this->assertEquals(-50, $report['quota_opening_litres']);
        $this->assertEquals(-50, $report['quota_closing_litres']);
        $this->assertEquals(-50, $report['tank_closing_litres']);
        $this->assertEquals(-50, DieselLedger::month('1405/08')['quota_opening_litres']);
    }

    public function test_حذف_تحویل_مانده_ماه_بعد_را_اصلاح_می‌کند_و_خواندن_گزارش_تراکنش_نمی‌سازد(): void
    {
        $this->quota('1405/07', 1000);
        $this->quota('1405/08', 500);
        $delivery = $this->delivery('1405/07/10', 200);
        $this->assertEquals(800, DieselLedger::month('1405/08')['quota_opening_litres']);
        $delivery->delete();
        $this->assertEquals(1000, DieselLedger::month('1405/08')['quota_opening_litres']);
        DieselLedger::month('1405/08');
        $this->assertSame(2, DieselAllocation::count());
        $this->assertSame(0, DieselDelivery::count());
    }

    public function test_نرخ_ناموجود_به_عنوان_برآورد_با_نرخ_پیش‌فرض_علامت_می‌خورد(): void
    {
        $this->bake('1405/06/10', 2);
        $report = DieselLedger::month('1405/08');
        $this->assertTrue($report['uses_default_rate']);
        $this->assertEquals(-10, $report['tank_opening_litres']);
    }

    public function test_ماهِ_نامعتبر_و_آینده_رد_می‌شوند_و_تاریخ_فارسی_پذیرفته_می‌شود(): void
    {
        $this->getJson('/api/v1/diesel/ledger?month=bad')->assertUnprocessable();
        $this->getJson('/api/v1/diesel/ledger?month=1405%2F09')->assertUnprocessable();
        $this->getJson('/api/v1/diesel/ledger?month='.urlencode('۱۴۰۵/۰۸'))->assertOk()->assertJsonPath('data.month', '1405/08');
    }

    public function test_نانوایی_دیگر_در_مانده_و_ریز_گردش_وارد_نمی‌شود(): void
    {
        $this->quota('1405/07', 1000);
        $other = Bakery::create(['name' => 'دیگر']);
        DieselAllocation::withoutGlobalScopes()->insert(['bakery_id' => $other->id, 'month_start' => Jalali::parse('1405/07/01'), 'month_label' => 'سهمیه دیگر', 'total_litres' => 9000, 'carryover_litres' => 0]);
        DieselDelivery::withoutGlobalScopes()->insert(['bakery_id' => $other->id, 'received_on' => Jalali::parse('1405/07/10'), 'litres' => 5000]);
        $this->getJson('/api/v1/diesel/ledger')->assertOk()->assertJsonPath('data.quota_opening_litres', 1000)
            ->assertJsonPath('data.tank_opening_litres', 0);
        $worker = User::factory()->create();
        $worker->assignRole('shater');
        $this->actingAs($worker, 'sanctum')->getJson('/api/v1/diesel/ledger')->assertForbidden();
    }

    public function test_پنل_مدیر_مانده_انتقالی_را_نشان_می‌دهد(): void
    {
        $this->quota('1405/07', 1000);
        $this->actingAs($this->admin, 'web');
        Livewire::test(DieselMonthlyReport::class)->assertSee('مانده از ماه قبل')->assertSee('1،000.00');
    }

    public function test_موجودی_و_حساب_بانکی_با_عوض_شدن_ماه_صفر_یا_دوبرابر_نمی‌شوند(): void
    {
        $account = BankAccount::create(['title' => 'حساب', 'opening_balance' => 120]);
        $stock = InventoryItem::ofKey(InventoryItem::FLOUR);
        $before = $stock->balance;
        $this->travelTo(Jalali::parse('1405/09/05')->setTime(12, 0));
        $this->assertEquals(120, $account->fresh()->balance);
        $this->assertEquals($before, $stock->fresh()->balance);
    }
}
