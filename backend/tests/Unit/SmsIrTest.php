<?php

namespace Tests\Unit;

use App\Support\Sms;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SmsIrTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container;
        $app->instance('config', new Repository(['sms' => [
            'driver' => 'smsir',
            'smsir' => ['key' => 'test-key', 'template_id' => 123, 'parameter' => 'CODE'],
        ]]));
        $app->instance(Factory::class, new Factory);
        $app->instance('log', new NullLogger);
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Sms::stopFaking();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Sms::stopFaking();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_کد_با_قالب_و_کلید_درست_ارسال_می‌شود(): void
    {
        Http::fake(['api.sms.ir/*' => Http::response(['status' => 1], 200)]);
        $this->assertTrue(Sms::send('+989121234567', 'متن آزمایشی', '123456'));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.sms.ir/v1/send/verify'
            && $request->hasHeader('X-API-KEY', 'test-key')
            && $request['Mobile'] === '09121234567'
            && $request['TemplateId'] === 123
            && $request['Parameters'] === [['Name' => 'CODE', 'Value' => '123456']]);
    }

    public function test_پاسخ_موفق_اچ‌تی‌تی‌پی_با_رد_سرویس_موفقیت_نیست(): void
    {
        Http::fake(['api.sms.ir/*' => Http::response(['status' => 0], 200)]);
        $this->assertFalse(Sms::send('09121234567', 'متن آزمایشی', '123456'));
    }

    public function test_بدون_کلید_هیچ_درخواستی_فرستاده_نمی‌شود(): void
    {
        config(['sms.smsir.key' => null]);
        Http::fake();
        $this->assertFalse(Sms::send('09121234567', 'متن آزمایشی', '123456'));
        Http::assertNothingSent();
    }

    public function test_قطع_ارتباط_به_خطای_قابل_کنترل_تبدیل_می‌شود(): void
    {
        Http::fake(fn () => throw new ConnectionException('خطای آزمایشی'));
        $this->assertFalse(Sms::send('09121234567', 'متن آزمایشی', '123456'));
    }
}
