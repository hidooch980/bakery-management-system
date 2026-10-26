<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\Sale;
use App\Models\User;
use App\Support\CurrentBakery;
use App\Support\Money;
use App\Support\SaleRecorder;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerWorkCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first()->update(['currency' => 'toman', 'bread_price' => 100, 'flour_bag_weight_kg' => 40]);
        CurrentBakery::forget();
        Money::forgetCache();
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');
        $this->actingAs($this->admin, 'sanctum');
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 4000, 'purchase');
    }

    private function workUrl(): string
    {
        return '/api/v1/seller-work/'.$this->seller->id;
    }

    private function sale(): Sale
    {
        $dough = DoughEntry::create(['user_id' => $this->seller->id, 'bag_count' => 1]);
        $batch = ChaneEntry::create(['dough_entry_id' => $dough->id, 'user_id' => $this->seller->id,
            'chane_count' => 100, 'normal_weight_kg' => 85, 'nanino_weight_kg' => 0, 'spray_flour_kg' => 0]);

        return SaleRecorder::record($batch, [['payment_type' => 'cash', 'bread_count' => 90, 'amount' => 9000]], $this->seller->id)[0];
    }

    private function saleInput(): array
    {
        $row = $this->getJson($this->workUrl())->assertOk()->json('data.sales.data.0');

        return ['bread_count' => 80, 'amount' => 8000, 'currency_label' => 'تومان', 'reason' => 'اشتباه در تعداد', 'version' => $row['version']];
    }

    private function flour(): ConsignmentFlour
    {
        $partner = Customer::create(['name' => 'همکار نمونه', 'type' => Customer::PARTNER_TYPE, 'is_active' => true]);

        return ConsignmentFlour::create(['customer_id' => $partner->id, 'direction' => 'borrowed', 'bags' => 5, 'occurred_on' => now()->subDay()]);
    }

    private function flourUrl(ConsignmentFlour $row): string
    {
        return '/api/v1/manager-corrections/partner-flour/'.$row->id;
    }

    private function flourInput(ConsignmentFlour $row): array
    {
        return array_merge($this->getJson($this->flourUrl($row))->assertOk()->json('data'), ['bags' => 3, 'reason' => 'اشتباه در ثبت کیسه']);
    }

    public function test_فروشنده_اجازه_خواندن_و_اصلاح_مدیریتی_را_ندارد(): void
    {
        $sale = $this->sale();
        $input = $this->saleInput();
        $this->actingAs($this->seller, 'sanctum');
        $this->getJson($this->workUrl())->assertForbidden();
        $this->patchJson($this->workUrl().'/sales/'.$sale->id, $input)->assertForbidden();
    }

    public function test_اصلاح_فروش_کسری_و_حساب_را_با_قیمت_تاریخی_تازه_می‌کند(): void
    {
        $sale = $this->sale();
        $input = $this->saleInput();
        Bakery::first()->update(['bread_price' => 200]);
        CurrentBakery::forget();
        $this->patchJson($this->workUrl().'/sales/'.$sale->id, $input)->assertOk();
        $sale->refresh();
        $this->assertEquals(80, $sale->bread_count);
        $this->assertEquals(20, $sale->shortfall_count);
        $this->assertEquals(2000, $sale->shortfall_amount);
        $this->assertEquals(0, $sale->amount_difference);
        $this->assertStringContainsString('اشتباه در تعداد', $sale->note);
    }

    public function test_فروش_بیش_از_چانه_و_تغییر_بدون_دلیل_رد_می‌شود(): void
    {
        $sale = $this->sale();
        $input = $this->saleInput();
        $this->patchJson($this->workUrl().'/sales/'.$sale->id, [...$input, 'bread_count' => 101])->assertUnprocessable();
        $this->patchJson($this->workUrl().'/sales/'.$sale->id, [...$input, 'reason' => ''])->assertUnprocessable();
        $this->assertEquals(90, $sale->fresh()->bread_count);
    }

    public function test_نسخه_قدیمی_و_فروش_تسویه_شده_تغییر_نمی‌کنند(): void
    {
        $sale = $this->sale();
        $input = $this->saleInput();
        $sale->update(['cash_settled_on' => now()]);
        $this->patchJson($this->workUrl().'/sales/'.$sale->id, $input)->assertStatus(409);
        $this->patchJson($this->workUrl().'/sales/'.$sale->id, $this->saleInput())->assertStatus(409);
        $this->assertEquals(90, $sale->fresh()->bread_count);
    }

    public function test_حضور_جامانده_با_دلیل_ثبت_و_با_سابقه_اصلاح_می‌شود(): void
    {
        $input = ['date' => now()->subDays(2)->toDateString(), 'in_time' => '07:00', 'out_time' => '14:00', 'out_next_day' => false, 'reason' => 'ورود ثبت نشده بود'];
        $row = $this->postJson($this->workUrl().'/attendance', $input)->assertCreated()->json('data');
        $this->assertEquals(420, $row['worked_minutes']);
        $this->postJson($this->workUrl().'/attendance', $input)->assertStatus(409);
        $fresh = $this->getJson($this->workUrl())->json('data.attendance.data.0');
        $this->patchJson($this->workUrl().'/attendance/'.$row['id'], [...$input, 'in_time' => '08:00', 'version' => $fresh['version']])
            ->assertOk()->assertJsonPath('data.worked_minutes', 360)->assertJsonPath('data.correction_reason', 'ورود ثبت نشده بود');
        $this->assertEquals(2, AuditLog::where('auditable_type', Attendance::class)->where('auditable_id', $row['id'])->count());
    }

    public function test_خروج_پیش_از_ورود_و_کارکرد_آینده_رد_می‌شود(): void
    {
        $input = ['date' => now()->subDays(2)->toDateString(), 'in_time' => '14:00', 'out_time' => '07:00', 'out_next_day' => false, 'reason' => 'اصلاح ساعت'];
        $this->postJson($this->workUrl().'/attendance', $input)->assertUnprocessable();
        $this->postJson($this->workUrl().'/attendance', [...$input, 'out_next_day' => true])->assertCreated();
        $this->postJson($this->workUrl().'/attendance', [...$input, 'date' => now()->addDay()->toDateString()])->assertUnprocessable();
    }

    public function test_اصلاح_آرد_انبار_و_سابقه_را_همزمان_تازه_می‌کند(): void
    {
        $row = $this->flour();
        $this->assertEquals(4200, InventoryItem::ofKey(InventoryItem::FLOUR)->fresh()->balance);
        $input = $this->flourInput($row);
        $this->patchJson($this->flourUrl($row), $input)->assertOk();
        $this->assertEquals(4120, InventoryItem::ofKey(InventoryItem::FLOUR)->fresh()->balance);
        $this->assertEquals(3, $row->fresh()->bags);
        $this->patchJson($this->flourUrl($row), $input)->assertStatus(409);
        $this->assertTrue(AuditLog::where('auditable_type', ConsignmentFlour::class)->where('event', AuditLog::UPDATED)->exists());
    }

    public function test_آرد_از_برگشت_قبلی_کمتر_نمی‌شود_و_همکار_خارجی_پذیرفته_نیست(): void
    {
        $row = $this->flour();
        $row->recordReturn(2, now());
        $input = $this->flourInput($row);
        $this->patchJson($this->flourUrl($row), [...$input, 'bags' => 1])->assertUnprocessable();
        $this->assertEquals(5, $row->fresh()->bags);
        $other = Bakery::create(['name' => 'نانوایی دیگر']);
        $partner = Customer::create(['name' => 'همکار نانوایی دیگر', 'type' => Customer::PARTNER_TYPE, 'bakery_id' => $other->id]);
        $partner->forceFill(['bakery_id' => $other->id])->save();
        $this->patchJson($this->flourUrl($row), [...$input, 'customer_id' => $partner->id])->assertNotFound();
    }
}
