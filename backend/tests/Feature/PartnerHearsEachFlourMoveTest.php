<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BaleSetting;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\NotificationSetting;
use App\Models\PartnerFlourChange;
use App\Models\SmsLog;
use App\Models\User;
use App\Support\Sms;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * پیام فوری ورود و خروج آرد امانی به همکار.
 * هیچ پیامی واقعاً نمی‌رود: بله با Http::fake و پیامک با Sms::fake.
 */
class PartnerHearsEachFlourMoveTest extends TestCase
{
    use RefreshDatabase;

    private Customer $partner;

    private bool $baleFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['tapi.bale.ai/*' => fn () => $this->baleFails
            ? Http::response(['ok' => false, 'error_code' => 500], 500)
            : Http::response(['ok' => true, 'result' => []])]);
        Sms::fake();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first()->update(['name' => 'خبازی ملازهی', 'flour_bag_weight_kg' => 40]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 8000, 'purchase');

        $this->partner = Customer::create([
            'name' => 'منصور پرکی', 'type' => Customer::PARTNER_TYPE, 'phone' => '09933653416', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Sms::stopFaking();
        parent::tearDown();
    }

    private function switchOn(bool $on = true): void
    {
        NotificationSetting::current()->update(['partner_flow_enabled' => $on]);
    }

    private function lend(float $bags, string $direction = 'lent'): ConsignmentFlour
    {
        return ConsignmentFlour::create([
            'customer_id' => $this->partner->id, 'direction' => $direction, 'bags' => $bags, 'occurred_on' => now()->toDateString(),
        ]);
    }

    private function flush(): void
    {
        $this->artisan('partners:flour-notices')->assertSuccessful();
    }

    private function later(int $seconds = 121): void
    {
        $this->travel($seconds)->seconds();
    }

    public function test_کلید_تازه_خاموش_است_و_با_کلید_خاموش_چیزی_ثبت_و_فرستاده_نمی‌شود(): void
    {
        $this->assertFalse(NotificationSetting::current()->partner_flow_enabled);

        $this->lend(5);
        $this->later();
        $this->flush();

        $this->assertSame(0, PartnerFlourChange::count());
        $this->assertSame([], Sms::sent());
        Http::assertNothingSent();
    }

    public function test_پیش_از_دو_دقیقه_چیزی_نمی‌رود_و_بعدش_یک_پیامک_با_مانده_می‌رود(): void
    {
        $this->switchOn();
        $this->lend(5);

        $this->later(60);
        $this->flush();
        $this->assertSame([], Sms::sent());

        $this->later(70);
        $this->flush();

        $sent = Sms::sent();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('خبازی ملازهی', $sent[0]['message']);
        $this->assertStringContainsString('تحویل به شما: ۵ کیسه', $sent[0]['message']);
        $this->assertStringContainsString('مانده: شما ۵ کیسه بدهکارید.', $sent[0]['message']);
    }

    public function test_چند_ثبت_پشت_سر_هم_یک_پیام_می‌شوند(): void
    {
        $this->switchOn();
        $this->lend(5);
        $this->later(90);
        $this->lend(3, 'borrowed');
        $this->later(90);
        $this->flush();
        $this->assertSame([], Sms::sent(), 'دو دقیقه از آخرین ثبت نگذشته');

        $this->later(40);
        $this->flush();
        $this->flush();

        $sent = Sms::sent();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('تحویل به شما: ۵ کیسه', $sent[0]['message']);
        $this->assertStringContainsString('دریافت از شما: ۳ کیسه', $sent[0]['message']);
        $this->assertStringContainsString('شما ۲ کیسه بدهکارید', $sent[0]['message']);
    }

    public function test_ثبت_و_حذف_در_همان_دو_دقیقه_چیزی_نمی‌فرستد(): void
    {
        $this->switchOn();
        $this->lend(5)->delete();
        $this->later();
        $this->flush();

        $this->assertSame([], Sms::sent());
        $this->assertSame(0, PartnerFlourChange::where('status', 'pending')->count());
    }

    public function test_ویرایش_پیش_از_ارسال_فقط_عدد_نهایی_را_می‌فرستد(): void
    {
        $this->switchOn();
        $this->lend(5)->update(['bags' => 4]);
        $this->later();
        $this->flush();

        $this->assertCount(1, Sms::sent());
        $this->assertStringContainsString('تحویل به شما: ۴ کیسه', Sms::sent()[0]['message']);
        $this->assertStringNotContainsString('اصلاحیه', Sms::sent()[0]['message']);
    }

    public function test_ویرایش_و_حذف_پس_از_ارسال_اصلاحیه_می‌فرستد(): void
    {
        $this->switchOn();
        $r = $this->lend(5);
        $this->later();
        $this->flush();

        $r->update(['bags' => 4]);
        $this->later();
        $this->flush();
        $this->assertStringContainsString('اصلاحیه: «تحویل به شما ۵ کیسه', Sms::sent()[1]['message']);
        $this->assertStringContainsString('به «تحویل به شما ۴ کیسه', Sms::sent()[1]['message']);

        $r->delete();
        $this->later();
        $this->flush();
        $this->assertStringContainsString('» حذف شد.', Sms::sent()[2]['message']);
        $this->assertStringContainsString('حساب آرد تسویه است.', Sms::sent()[2]['message']);
        $this->assertCount(3, Sms::sent());
    }

    public function test_تغییر_فقط_توضیح_چیزی_نمی‌فرستد(): void
    {
        $this->switchOn();
        $r = $this->lend(5);
        $this->later();
        $this->flush();

        $r->update(['note' => 'آرد ستاره']);
        $this->later();
        $this->flush();

        $this->assertCount(1, Sms::sent());
    }

    public function test_روشن‌کردن_کلید_برای_ثبت‌های_گذشته_چیزی_نمی‌فرستد(): void
    {
        $old = $this->lend(5);
        $this->switchOn();

        $old->update(['bags' => 6]);
        $this->later();
        $this->flush();

        $this->assertSame([], Sms::sent(), 'اصلاحیه فقط برای چیزی که قبلاً گفته شده');
    }

    public function test_برگشت_و_تسویهٔ_ردیف_قدیمی_حرکت_تازه_است_و_فرستاده_می‌شود(): void
    {
        $old = $this->lend(10);
        $this->switchOn();

        $old->recordReturn(4, now());
        $this->later();
        $this->flush();
        $this->assertStringContainsString('برگشت از شما: ۴ کیسه', Sms::sent()[0]['message']);

        $old->refresh()->update(['settled_on' => now()->toDateString()]);
        $this->later();
        $this->flush();

        $this->assertCount(2, Sms::sent());
        $this->assertStringContainsString('برگشت از شما (تسویه): ۶ کیسه', Sms::sent()[1]['message']);
        $this->assertStringContainsString('حساب آرد تسویه است.', Sms::sent()[1]['message']);
    }

    public function test_برگشت_کاملی_که_خودش_تسویه_می‌کند_یک_سطر_دارد(): void
    {
        $this->switchOn();
        $r = $this->lend(5);
        $this->later();
        $this->flush();

        $r->recordReturn(5, now());
        $this->later();
        $this->flush();

        $msg = Sms::sent()[1]['message'];
        $this->assertStringContainsString('برگشت از شما: ۵ کیسه', $msg);
        $this->assertStringNotContainsString('تسویه):', $msg);
    }

    public function test_همکار_وصل_از_بله_می‌گیرد_با_متن_کامل(): void
    {
        $this->switchOn();
        $this->partner->forceFill(['bale_chat_id' => '777'])->save();
        BaleSetting::current()->forceFill(['bot_token' => '123:fake', 'deliver_via_bale' => true])->save();

        $this->lend(5);
        $this->later();
        $this->flush();

        $this->assertSame([], Sms::sent());
        Http::assertSent(fn (Request $r) => $r['chat_id'] === '777'
            && str_contains($r['text'], 'خبازی ملازهی — آرد امانی')
            && str_contains($r['text'], 'شما ۵ کیسه به خبازی ملازهی بدهکارید.'));
        $this->assertSame('partner_flow', SmsLog::latest('id')->first()->kind);
    }

    public function test_خطای_بله_یک_پیامک_کوتاه_جایگزین_می‌فرستد(): void
    {
        $this->switchOn();
        $this->baleFails = true;
        $this->partner->forceFill(['bale_chat_id' => '777'])->save();
        BaleSetting::current()->forceFill(['bot_token' => '123:fake', 'deliver_via_bale' => true])->save();

        $this->lend(5);
        $this->later();
        $this->flush();
        $this->flush();

        Http::assertSentCount(1);
        $this->assertCount(1, Sms::sent());
        $this->assertStringContainsString('آرد امانی ', Sms::sent()[0]['message']);
    }

    public function test_خاموش‌کردن_کلید_پیام‌های_مانده_را_نمی‌فرستد(): void
    {
        $this->switchOn();
        $this->lend(5);
        $this->switchOn(false);
        $this->later();
        $this->flush();

        $this->assertSame([], Sms::sent());
        $this->assertSame('dropped', PartnerFlourChange::first()->status);
    }

    public function test_کلید_یک_نانوایی_نانوایی_دیگر_را_روشن_نمی‌کند(): void
    {
        $this->switchOn();
        $other = Bakery::create(['name' => 'خبازی دلشادی', 'currency' => 'toman']);

        $this->assertFalse((bool) NotificationSetting::query()->acrossBakeries()->where('bakery_id', $other->id)->value('partner_flow_enabled'));
        $this->assertSame(0, NotificationSetting::query()->acrossBakeries()->where('partner_flow_enabled', true)->where('bakery_id', '!=', Bakery::first()->id)->count());
    }
}
