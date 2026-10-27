<?php

namespace Tests\Feature;

use App\Filament\Resources\PartnerResource\Pages\PartnerStatementPage;
use App\Models\Bakery;
use App\Models\BaleSetting;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\CurrentBakery;
use App\Support\PartnerFlourReport;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PartnerFlourReportTest extends TestCase
{
    use RefreshDatabase;

    private Customer $partner;

    private bool $smsAccepted = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first()->update(['name' => 'نانوایی هیدوچ', 'flour_bag_weight_kg' => 40]);
        CurrentBakery::forget();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 8000, 'purchase');
        $this->partner = Customer::create([
            'name' => 'نانوایی کنت', 'type' => Customer::PARTNER_TYPE,
            'phone' => '۰۹۱۲۱۲۳۴۵۶۷', 'is_active' => true,
        ]);
        config(['sms.driver' => 'smsir', 'sms.smsir.key' => 'test-key', 'sms.from' => '30001234']);
        Http::preventStrayRequests();
        Http::fake(['api.sms.ir/*' => fn () => Http::response(['status' => $this->smsAccepted ? 1 : 0], 200)]);
    }

    private function record(string $direction, float $bags, string $date = '2026-10-01'): ConsignmentFlour
    {
        return ConsignmentFlour::create([
            'customer_id' => $this->partner->id, 'direction' => $direction,
            'bags' => $bags, 'occurred_on' => $date,
        ]);
    }

    private function send(): void
    {
        $preview = PartnerFlourReport::preview($this->partner);
        PartnerFlourReport::send($this->partner, $preview['fingerprint']);
    }

    public function test_گردش_و_مانده_با_همان_پیش‌نمایش_به_شماره_همکار_ارسال_می‌شود(): void
    {
        $lent = $this->record('lent', 20);
        $borrowed = $this->record('borrowed', 8);
        $lent->recordReturn(2, '2026-10-02');
        $borrowed->recordReturn(1, '2026-10-02');
        $preview = PartnerFlourReport::preview($this->partner);
        $this->assertSame('09121234567', $preview['phone']);
        $this->assertStringContainsString('تحویلی به شما: 20 کیسه', $preview['message']);
        $this->assertStringContainsString('دریافتی از شما: 8 کیسه', $preview['message']);
        $this->assertStringContainsString('برگشت از شما: 2 کیسه', $preview['message']);
        $this->assertStringContainsString('برگشت به شما: 1 کیسه', $preview['message']);
        $this->assertStringContainsString('شما 11 کیسه به نانوایی هیدوچ بدهکارید.', $preview['message']);
        PartnerFlourReport::send($this->partner, $preview['fingerprint']);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.sms.ir/v1/send/bulk'
            && $r['mobiles'] === ['09121234567'] && $r['messageText'] === $preview['message']);
        Http::assertSentCount(1);
    }

    public function test_بازه_تاریخی_و_بدهکار_بودن_نانوایی_با_مانده_فعلی_اشتباه_نمی‌شود(): void
    {
        $this->record('borrowed', 5, '2026-09-01');
        $this->record('lent', 2, '2026-10-01');
        $this->record('lent', 10, '2026-11-01');
        $preview = PartnerFlourReport::preview($this->partner, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $this->assertStringContainsString('مانده اول بازه: نانوایی هیدوچ 5 کیسه به شما بدهکار است.', $preview['message']);
        $this->assertStringContainsString('مانده پایان بازه: نانوایی هیدوچ 3 کیسه به شما بدهکار است.', $preview['message']);
        $this->assertStringContainsString('مانده فعلی: شما 7 کیسه به نانوایی هیدوچ بدهکارید.', $preview['message']);
    }

    public function test_دکمه_پنل_پیش‌نمایش_می‌دهد_و_تنها_پس_از_تأیید_ارسال_می‌کند(): void
    {
        $this->record('lent', 4);
        $page = Livewire::test(PartnerStatementPage::class, ['record' => $this->partner->id])
            ->mountAction('sendStatementReport')
            ->assertSet('mountedActionsData.0.message', PartnerFlourReport::preview($this->partner)['message']);
        Http::assertNothingSent();
        $page->callMountedAction()->assertHasNoActionErrors();
        Http::assertSentCount(1);
    }

    public function test_پیام_تکراری_در_یک_دقیقه_ارسال_نمی‌شود(): void
    {
        $this->send();
        try {
            $this->send();
            $this->fail('ارسال تکراری نباید پذیرفته شود.');
        } catch (ValidationException $e) {
            Http::assertSentCount(1);
        }
    }

    public function test_تغییر_شماره_یا_حساب_پس_از_پیش‌نمایش_ارسال_را_متوقف_می‌کند(): void
    {
        $preview = PartnerFlourReport::preview($this->partner);
        $this->partner->update(['phone' => '09121234568']);
        try {
            PartnerFlourReport::send($this->partner, $preview['fingerprint']);
            $this->fail('پیش‌نمایش قدیمی نباید ارسال شود.');
        } catch (ValidationException $e) {
            Http::assertNothingSent();
        }
    }

    public function test_حالت_لاگ_و_نبود_خط_و_رد_سرویس_موفقیت_نیست(): void
    {
        foreach (['log', 'missing-line', 'provider-rejected'] as $case) {
            config(['sms.driver' => $case === 'log' ? 'log' : 'smsir', 'sms.from' => $case === 'missing-line' ? null : '30001234']);
            $this->smsAccepted = false;
            try {
                $this->send();
                $this->fail('ارسال ناموفق نباید موفق گزارش شود.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('message', $e->errors());
            }
        }
        Http::assertSentCount(1);
    }

    public function test_شماره_نامعتبر_ارسال_نمی‌شود(): void
    {
        $this->partner->update(['phone' => '02112345678']);
        try {
            $this->send();
            $this->fail('شماره نامعتبر نباید ارسال شود.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phone', $e->errors());
            Http::assertNothingSent();
        }
    }

    public function test_فروشنده_اجازه_ارسال_پیامک_از_پنل_ندارد(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');
        $this->actingAs($seller);
        try {
            $this->send();
            $this->fail('فروشنده نباید پیامک پنل بفرستد.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            Http::assertNothingSent();
        }
    }

    public function test_همکار_نانوایی_دیگر_حتی_با_مدل_آماده_گزارش_داده_نمی‌شود(): void
    {
        $other = Bakery::create(['name' => 'نانوایی دیگر']);
        $partner = CurrentBakery::for($other->id, fn () => Customer::create([
            'name' => 'همکار دیگر', 'type' => Customer::PARTNER_TYPE, 'phone' => '09121234568',
        ]));
        $this->expectException(ModelNotFoundException::class);
        PartnerFlourReport::preview($partner);
    }

    private function enableBale(): void
    {
        BaleSetting::current()->update([
            'safir_enabled' => true, 'safir_api_key' => 'safir-test-key', 'safir_bot_id' => 12345,
        ]);
    }

    public function test_گزارش_برای_عضو_بله_فقط_به_بله_می‌رود(): void
    {
        $this->enableBale();
        Http::fake(['safir.bale.ai/*' => Http::response(['message_id' => 'bale-message', 'error_data' => null], 200)]);
        $preview = PartnerFlourReport::preview($this->partner);
        $this->assertSame('بله؛ پیامک فقط در صورت عدم عضویت', $preview['channel']);
        $this->assertSame('بله', PartnerFlourReport::send($this->partner, $preview['fingerprint']));
        Http::assertSent(fn ($r) => $r->url() === 'https://safir.bale.ai/api/v3/send_message'
            && $r->hasHeader('api-access-key', 'safir-test-key')
            && $r['phone_number'] === '989121234567'
            && $r['bot_id'] === 12345 && filled($r['request_id'])
            && $r['message_data']['message']['text'] === $preview['message']);
        Http::assertSentCount(1);
        $setting = BaleSetting::current();
        $this->assertNotSame('safir-test-key', $setting->getRawOriginal('safir_api_key'));
        $this->assertArrayNotHasKey('safir_api_key', $setting->toArray());
    }

    public function test_فقط_عدم_عضویت_صریح_همان_شماره_به_پیامک_می‌رود(): void
    {
        $this->enableBale();
        Http::fake(['safir.bale.ai/*' => Http::response([
            'error_data' => [['phone_number' => '989121234567', 'code' => 17]],
        ], 200)]);
        $preview = PartnerFlourReport::preview($this->partner);
        $this->assertSame('پیامک', PartnerFlourReport::send($this->partner, $preview['fingerprint']));
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.sms.ir/v1/send/bulk'
            && $r['mobiles'] === ['09121234567'] && $r['messageText'] === $preview['message']);
    }

    public function test_کمبود_اعتبار_و_خطای_بله_و_شماره_دیگر_به_پیامک_تبدیل_نمی‌شود(): void
    {
        $this->enableBale();
        $code = 20;
        Http::fake(['safir.bale.ai/*' => function () use (&$code) {
            return Http::response([
                'error_data' => [['phone_number' => '989121234568', 'code' => $code]],
            ], 200);
        }]);
        foreach ([20, 2, 17] as $code) {
            try {
                $this->send();
                $this->fail('این خطا مجوز پیامک جایگزین نیست.');
            } catch (ValidationException $e) {
                Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.sms.ir'));
            }
        }
        $ids = Http::recorded()->map(fn ($pair) => $pair[0]['request_id'])->unique();
        $this->assertCount(1, $ids);
    }

    public function test_قطع_پاسخ_بله_باعث_ارسال_دوگانه_نمی‌شود(): void
    {
        $this->enableBale();
        Http::fake(['safir.bale.ai/*' => fn () => throw new ConnectionException('خطای آزمایشی')]);
        try {
            $this->send();
            $this->fail('پاسخ نامشخص نباید ارسال موفق حساب شود.');
        } catch (ValidationException $e) {
            Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.sms.ir'));
        }
    }

    public function test_بله_فعال_بدون_کلید_و_بازو_هیچ_پیامی_نمی‌فرستد(): void
    {
        BaleSetting::current()->update(['safir_enabled' => true]);
        try {
            $this->send();
            $this->fail('تنظیمات ناقص نباید با پیامک پنهان شود.');
        } catch (ValidationException $e) {
            Http::assertNothingSent();
        }
    }
}
