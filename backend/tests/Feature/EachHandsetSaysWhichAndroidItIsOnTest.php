<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * هر کارمند روی کدام اندروید وصل است.
 *
 * فهرست دستگاه‌ها مدت‌هاست نام گوشی و نسخهٔ اپ را می‌گوید، و معلوم شد
 * آن دو سوم از سؤال است که جواب نمی‌دهد. فروشنده گفت APK تازه نصب
 * نمی‌شود؛ فهرست مدل گوشی و نسخه‌ای سه انتشار عقب‌تر را نشان می‌داد،
 * و هیچ‌کدام آن چیزی را نمی‌گفت که اهمیت داشت — گوشی روی اندرویدی
 * قدیمی‌تر از آنی بود که اپ از هفتمین تغییرش به بعد لازم دارد، پس هیچ
 * نسخه‌ای از آن موقع تا حالا نمی‌توانسته رویش نصب شود.
 *
 * هیچ صفحه‌ای این را نمی‌توانست بگوید. این آزمون برای همان است.
 */
class EachHandsetSaysWhichAndroidItIsOnTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    /**
     * یک توکن واقعی، نه `actingAs`.
     *
     * ‏`actingAs(…, 'sanctum')` به درخواست یک TransientToken می‌دهد که
     * ردیفی ندارد تا نسخه رویش نوشته شود، و هرگز در فهرست دستگاه‌ها
     * دیده نمی‌شود — پس آزمونی که روی آن ساخته شود، در برابر
     * میان‌افزاری که هیچ کاری نمی‌کند هم سبز می‌ماند.
     */
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        $this->token = $this->seller->createToken('Samsung SM-J250F')->plainTextToken;
    }

    public function test_گوشی_اندرویدش_را_می‌گوید_و_فهرست_نشانش_می‌دهد(): void
    {
        $this->withToken($this->token)
            ->withHeaders([
                'X-Device-OS' => 'Android 13',
                'X-Device-SDK' => '33',
            ])
            ->getJson('/api/v1/devices')
            ->assertOk();

        $row = $this->devices()[0];

        $this->assertSame('Android 13', $row['os_version']);
        $this->assertSame(33, $row['sdk_int']);
    }

    public function test_گوشیِ_قدیمی‌تر_از_آنکه_نسخه_رویش_برود_همین‌طور_نامیده_می‌شود(): void
    {
        // اندروید ۶.۰. اپ از هفتمین تغییرش به بعد ۲۴ لازم دارد، پس
        // هیچ نسخه‌ای از آن موقع تا حالا اینجا نصب نمی‌شود.
        $this->report(os: 'Android 6.0', sdk: 23);

        $row = $this->devices()[0];

        $this->assertFalse($row['can_install_updates']);
    }

    public function test_گوشی‌ای_که_نسخه_را_می‌گیرد_هشدار_نمی‌گیرد(): void
    {
        $this->report(os: 'Android 7.0', sdk: 24);

        // دقیقاً روی کف: مرز همان جایی است که آدم اشتباه می‌کند، و
        // گوشی فروشنده هم درست روی همان نشسته.
        $this->assertTrue($this->devices()[0]['can_install_updates']);
    }

    /**
     * گوشی‌ای که هرگز سطحش را گزارش نکرده.
     *
     * «به‌روزرسانی نمی‌گیرد» کسی را می‌فرستد گوشیِ سالم عوض کند، پس
     * «نامشخص» جوابِ خودش است نه یک جوابِ غلط.
     */
    public function test_گوشیِ_گزارش‌نداده_جا‌مانده_خوانده_نمی‌شود(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/devices')
            ->assertOk();

        $row = $this->devices()[0];

        $this->assertNull($row['os_version']);
        $this->assertNull($row['can_install_updates']);
    }

    public function test_نسخه_به‌روز_می‌ماند_نه_اینکه_سر_ورود_یخ_بزند(): void
    {
        // بعد از آپدیتِ سیستم کسی دوباره وارد نمی‌شود؛ توکن از آن عمر
        // بیشتری دارد. فیلدی که بگوید اندروید ۹ در حالی که گوشی روی ۱۰
        // است، از فیلد خالی بدتر است — با اطمینانِ یک واقعیت، غلط است.
        $this->report(os: 'Android 9', sdk: 28);
        $this->report(os: 'Android 10', sdk: 29);

        $row = $this->devices()[0];

        $this->assertSame('Android 10', $row['os_version']);
        $this->assertSame(29, $row['sdk_int']);
    }

    public function test_هدرِ_ساختگی_ذخیره_نمی‌شود_دور_ریخته_می‌شود(): void
    {
        $this->report(os: 'Android 13', sdk: 33);

        // در ستونی نوشته می‌شود که صاحب می‌خواندش، و از شبکه می‌آید.
        $this->report(os: '<script>alert(1)</script>', sdk: 999999);

        $row = $this->devices()[0];

        $this->assertSame('Android 13', $row['os_version']);
        $this->assertSame(33, $row['sdk_int']);
    }

    public function test_گزارش‌دادن_هیچ‌وقت_جلوی_یک_درخواست_را_نمی‌گیرد(): void
    {
        // گوشی‌ای که نتواند نسخه‌اش را بگوید، هنوز نان می‌فروشد.
        $this->withToken($this->token)
            ->withHeaders(['X-Device-OS' => str_repeat('x', 500)])
            ->getJson('/api/v1/devices')
            ->assertOk();
    }

    // ------------------------------------------------------ کمک‌کننده‌ها

    private function report(string $os, int $sdk): void
    {
        $this->withToken($this->token)
            ->withHeaders(['X-Device-OS' => $os, 'X-Device-SDK' => (string) $sdk])
            ->getJson('/api/v1/devices')
            ->assertOk();
    }

    private function devices(): array
    {
        return $this->withToken($this->token)
            ->getJson('/api/v1/devices')
            ->assertOk()
            ->json('data.devices');
    }
}
