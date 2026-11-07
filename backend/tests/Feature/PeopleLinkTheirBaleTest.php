<?php

namespace Tests\Feature;

use App\Filament\Pages\BaleLinks;
use App\Models\Bakery;
use App\Models\BaleSetting;
use App\Models\BaleUpdate;
use App\Models\Customer;
use App\Models\NotificationSetting;
use App\Models\SmsLog;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentBakery;
use App\Support\Notify;
use App\Support\Sms;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * وصل‌شدن افراد به ربات بله و فرستندهٔ مشترک بله/پیامک.
 * هیچ پیامی واقعاً نمی‌رود: بله با Http::fake و پیامک با Sms::fake.
 */
class PeopleLinkTheirBaleTest extends TestCase
{
    use RefreshDatabase;

    private Bakery $bakery;

    private BaleSetting $settings;

    private string $secret;

    private bool $baleFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['tapi.bale.ai/*' => fn () => $this->baleFails
            ? Http::response(['ok' => false, 'error_code' => 500, 'description' => 'bot123:fake-token down'], 500)
            : Http::response(['ok' => true, 'result' => []])]);
        Sms::fake();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        $this->bakery = Bakery::query()->orderBy('id')->firstOrFail();
        $this->secret = str_repeat('a', 48);

        $this->settings = CurrentBakery::for($this->bakery->id, fn () => tap(BaleSetting::current(), fn ($s) => $s->forceFill([
            'bot_token' => '123:fake-token',
            'webhook_secret' => $this->secret,
            'link_enabled' => true,
        ])->save()));
    }

    protected function tearDown(): void
    {
        Sms::stopFaking();
        parent::tearDown();
    }

    private function inBakery(callable $fn): mixed
    {
        return CurrentBakery::for($this->bakery->id, $fn);
    }

    private function partner(string $phone, string $name = 'منصور پرکی'): Customer
    {
        return $this->inBakery(fn () => Customer::create(['name' => $name, 'type' => 'partner', 'phone' => $phone, 'is_active' => true]));
    }

    private function contactUpdate(int $updateId, string $phone, int $from = 777, ?int $contactUser = 777, string $chatType = 'private'): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => $from],
                'chat' => ['id' => $from, 'type' => $chatType],
                'contact' => array_filter(['phone_number' => $phone, 'user_id' => $contactUser], fn ($v) => $v !== null),
            ],
        ];
    }

    private function hook(array $update)
    {
        return $this->postJson('/api/v1/bale/webhook/'.$this->secret, $update);
    }

    public function test_شمارهٔ_خود_فرستنده_با_رقم_فارسی_و_پیشوند_۹۸_وصل_می‌شود(): void
    {
        $p = $this->partner('09933653416');

        $this->hook($this->contactUpdate(1, '+۹۸ ۹۹۳ ۳۶۵ ۳۴۱۶'))->assertOk()->assertJson(['outcome' => 'linked']);

        $this->assertSame('777', $p->fresh()->bale_chat_id);
        $this->assertNotNull($p->fresh()->bale_linked_at);
    }

    public function test_همهٔ_قالب‌های_شمارهٔ_ایران_یکسان_خوانده_می‌شوند(): void
    {
        foreach (['09154375658', '9154375658', '989154375658', '00989154375658', '+989154375658', '۰۹۱۵۴۳۷۵۶۵۸', '٠٩١٥٤٣٧٥٦٥٨'] as $raw) {
            $this->assertSame('09154375658', Sms::normalise($raw), $raw);
        }
        $this->assertNull(Sms::normalise('12345'));
    }

    public function test_کارت_تماس_شخص_دیگر_پذیرفته_نمی‌شود(): void
    {
        $p = $this->partner('09933653416');

        $this->hook($this->contactUpdate(2, '09933653416', from: 777, contactUser: 888))->assertJson(['outcome' => 'foreign_contact']);
        $this->hook($this->contactUpdate(3, '09933653416', from: 777, contactUser: null))->assertJson(['outcome' => 'foreign_contact']);

        $this->assertNull($p->fresh()->bale_chat_id);
    }

    public function test_چت_گروهی_نادیده_گرفته_می‌شود_و_پاسخی_نمی‌گیرد(): void
    {
        $p = $this->partner('09933653416');

        $this->hook($this->contactUpdate(4, '09933653416', chatType: 'group'))->assertJson(['outcome' => 'ignored']);

        $this->assertNull($p->fresh()->bale_chat_id);
        Http::assertNothingSent();
    }

    public function test_شمارهٔ_نامعتبر_و_ناشناس_رد_می‌شود(): void
    {
        $this->hook($this->contactUpdate(5, '12345'))->assertJson(['outcome' => 'invalid_phone']);
        $this->hook($this->contactUpdate(6, '09120000000'))->assertJson(['outcome' => 'not_found']);
    }

    public function test_شمارهٔ_مشترک_میان_دو_رکورد_مبهم_است_و_به_هیچ‌کدام_وصل_نمی‌شود(): void
    {
        $a = $this->partner('09151485200', 'محمد صالح ایزدخواه');
        $s = $this->inBakery(fn () => Supplier::create(['name' => 'کارخانه', 'phone' => '+989151485200', 'is_active' => true]));

        $this->hook($this->contactUpdate(7, '09151485200'))->assertJson(['outcome' => 'ambiguous']);

        $this->assertNull($a->fresh()->bale_chat_id);
        $this->assertNull($s->fresh()->bale_chat_id);
    }

    public function test_کارمند_با_شماره‌اش_وصل_می‌شود(): void
    {
        $u = $this->inBakery(fn () => User::factory()->create(['phone' => '09155470870', 'bakery_id' => $this->bakery->id]));

        $this->hook($this->contactUpdate(8, '09155470870'))->assertJson(['outcome' => 'linked']);

        $this->assertSame('777', $u->fresh()->bale_chat_id);
    }

    public function test_اتصال_موجود_با_حساب_دیگر_بازنویسی_نمی‌شود(): void
    {
        $p = $this->partner('09933653416');
        $p->forceFill(['bale_chat_id' => '555'])->save();

        $this->hook($this->contactUpdate(9, '09933653416', from: 777))->assertJson(['outcome' => 'taken']);

        $this->assertSame('555', $p->fresh()->bale_chat_id);
    }

    public function test_رکورد_نانوایی_دیگر_با_همان_شماره_دیده_نمی‌شود(): void
    {
        $other = Bakery::create(['name' => 'خبازی دلشادی', 'currency' => 'toman']);
        $theirs = CurrentBakery::for($other->id, fn () => Customer::create(['name' => 'همکار دیگر', 'type' => 'partner', 'phone' => '09933653416', 'is_active' => true]));
        $ours = $this->partner('09933653416');

        $this->hook($this->contactUpdate(10, '09933653416'))->assertJson(['outcome' => 'linked']);

        $this->assertSame('777', $ours->fresh()->bale_chat_id);
        $this->assertNull(Customer::query()->acrossBakeries()->find($theirs->id)->bale_chat_id);
    }

    public function test_آپدیت_تکراری_دوباره_پردازش_و_پاسخ_داده_نمی‌شود(): void
    {
        $this->partner('09933653416');

        $this->hook($this->contactUpdate(11, '09933653416'))->assertJson(['outcome' => 'linked']);
        $this->hook($this->contactUpdate(11, '09933653416'))->assertJson(['outcome' => 'duplicate']);

        Http::assertSentCount(1);
        $this->assertSame(1, BaleUpdate::query()->acrossBakeries()->where('update_id', 11)->count());
    }

    public function test_با_کلید_خاموش_ربات_هیچ_کاری_نمی‌کند(): void
    {
        $p = $this->partner('09933653416');
        $this->settings->forceFill(['link_enabled' => false])->save();

        $this->hook($this->contactUpdate(12, '09933653416'))->assertJson(['outcome' => 'disabled']);

        $this->assertNull($p->fresh()->bale_chat_id);
        Http::assertNothingSent();
    }

    public function test_نشانی_مخفی_نادرست_۴۰۴_می‌گیرد(): void
    {
        $this->postJson('/api/v1/bale/webhook/'.str_repeat('b', 48), $this->contactUpdate(13, '09933653416'))->assertNotFound();
    }

    public function test_همهٔ_کلیدهای_تازه_خاموش_شروع_می‌شوند(): void
    {
        $b = Bakery::create(['name' => 'تازه', 'currency' => 'toman']);
        CurrentBakery::for($b->id, function () {
            $s = BaleSetting::current()->refresh();
            $this->assertFalse((bool) $s->link_enabled);
            $this->assertFalse((bool) $s->deliver_via_bale);
            $this->assertFalse((bool) NotificationSetting::current()->sms_seller_debt);
        });
    }

    public function test_فرد_وصل_از_بله_پیام_می‌گیرد_نه_پیامک(): void
    {
        $p = $this->partner('09933653416');
        $p->forceFill(['bale_chat_id' => '777'])->save();
        $this->settings->forceFill(['deliver_via_bale' => true])->save();

        $log = $this->inBakery(fn () => Notify::person('partner_weekly', $p, 'سلام', true));

        $this->assertSame(['bale', 'sent'], [$log->channel, $log->status]);
        $this->assertSame([], Sms::sent());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/sendMessage') && $r['chat_id'] === '777');
    }

    public function test_فرد_وصل‌نشده_پیامک_می‌گیرد(): void
    {
        $p = $this->partner('09933653416');
        $this->settings->forceFill(['deliver_via_bale' => true])->save();

        $log = $this->inBakery(fn () => Notify::person('partner_weekly', $p, 'سلام', true));

        $this->assertSame(['sms', 'sent'], [$log->channel, $log->status]);
        $this->assertCount(1, Sms::sent());
        Http::assertNothingSent();
    }

    public function test_با_کلید_نوع_پیام_خاموش_هیچ_چیز_نمی‌رود(): void
    {
        $p = $this->partner('09933653416');
        $p->forceFill(['bale_chat_id' => '777'])->save();
        $this->settings->forceFill(['deliver_via_bale' => true])->save();

        $log = $this->inBakery(fn () => Notify::person('partner_weekly', $p, 'سلام', false));

        $this->assertSame('skipped', $log->status);
        $this->assertSame([], Sms::sent());
        Http::assertNothingSent();
    }

    public function test_خطای_بله_یک_پیامک_جایگزین_می‌فرستد_و_توکن_ثبت_نمی‌شود(): void
    {
        $this->baleFails = true;
        $p = $this->partner('09933653416');
        $p->forceFill(['bale_chat_id' => '777'])->save();
        $this->settings->forceFill(['deliver_via_bale' => true])->save();

        $log = $this->inBakery(fn () => Notify::person('staff_penalty', $p, 'اخطار', true));

        $this->assertSame(['sms', 'sent'], [$log->channel, $log->status]);
        $baleLog = SmsLog::query()->acrossBakeries()->where('channel', 'bale')->sole();
        $this->assertSame('failed', $baleLog->status);
        $this->assertStringNotContainsString('fake-token', (string) $baleLog->error);
        $this->assertCount(1, Sms::sent());
        Http::assertSentCount(1);
    }

    public function test_با_ارسال_از_بله_خاموش_فرد_وصل_هم_پیامک_می‌گیرد(): void
    {
        $p = $this->partner('09933653416');
        $p->forceFill(['bale_chat_id' => '777'])->save();

        $log = $this->inBakery(fn () => Notify::person('partner_weekly', $p, 'سلام', true));

        $this->assertSame('sms', $log->channel);
        Http::assertNothingSent();
    }

    public function test_کد_بازیابی_رمز_همیشه_پیامکی_است_حتی_برای_فرد_وصل(): void
    {
        $u = $this->inBakery(fn () => User::factory()->create(['phone' => '09159991669', 'bakery_id' => $this->bakery->id, 'is_active' => true]));
        $u->forceFill(['bale_chat_id' => '777'])->save();
        $this->settings->forceFill(['deliver_via_bale' => true])->save();

        $this->postJson('/api/v1/forgot-password', ['phone' => '09159991669'])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, SmsLog::query()->acrossBakeries()->where('channel', 'bale')->count());
    }

    public function test_دستورهای_هفتگی_و_بدهی_با_کلیدهای_خاموش_چیزی_نمی‌فرستند(): void
    {
        $this->partner('09933653416');

        $this->artisan('partners:weekly-sms')->assertSuccessful();
        $this->artisan('sellers:debt-reminders')->assertSuccessful();

        $this->assertSame([], Sms::sent());
        Http::assertNothingSent();
    }

    public function test_مدیر_صفحهٔ_اتصال_را_با_پیوند_و_کد_تصویری_می‌بیند(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'bakery_id' => $this->bakery->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->settings->forceFill(['bot_username' => 'nanvaei_bot'])->save();
        $p = $this->partner('09933653416');
        $p->forceFill(['bale_chat_id' => '777'])->save();

        Livewire::test(BaleLinks::class)
            ->assertOk()
            ->assertSee('https://ble.ir/nanvaei_bot')
            ->assertSee('<svg', false)
            ->assertSee('وصل به بله')
            ->call('unlink', 'customer', $p->id);

        $this->assertNull($p->fresh()->bale_chat_id);
        Http::assertNothingSent();
    }
}
