<?php

namespace Tests\Feature;

use App\Filament\Pages\StaffHandsets;
use App\Models\Bakery;
use App\Models\User;
use App\Support\CurrentBakery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * صاحب می‌بیند هر کارمند با چه گوشی و چه اندرویدی وصل است.
 *
 * ثبتش از قبل بود، ولی فهرست دستگاه‌ها فقط مالِ خودِ شخص است:
 * فروشنده گوشیِ خودش را می‌دید و صاحب گوشیِ خودش را. وقتی فروشنده
 * می‌گفت «نصب نمی‌شود»، هیچ صفحه‌ای نبود که صاحب رویش نگاه کند و
 * ببیند چرا.
 */
class GushiyeHarKarmandDidaMishavadTest extends TestCase
{
    use RefreshDatabase;

    private Bakery $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->shop = Bakery::create(['name' => 'نانوایی ما', 'currency' => 'toman']);

        $this->owner = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $this->shop->id,
            'name' => 'مالک',
        ]);
        $this->owner->assignRole('admin');

        CurrentBakery::forget();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_گوشیِ_فروشنده_با_اندرویدش_دیده_می‌شود(): void
    {
        $this->sellerOn('فروشندهٔ ما', 'Samsung SM-A546E', 'Android 13', 33);

        Livewire::actingAs($this->owner)
            ->test(StaffHandsets::class)
            ->assertSee('فروشندهٔ ما')
            ->assertSee('Samsung SM-A546E')
            ->assertSee('Android 13');
    }

    public function test_گوشیِ_جامانده_علامت_می‌خورد(): void
    {
        // اندروید ۶.۰ — زیر کفِ ۲۴.
        $this->sellerOn('فروشندهٔ قدیمی', 'Samsung SM-J250F', 'Android 6.0', 23);

        Livewire::actingAs($this->owner)
            ->test(StaffHandsets::class)
            ->assertSee('به‌روزرسانی نمی‌گیرد')
            ->assertSee('گوشی نسخهٔ تازه را نمی‌گیرد');
    }

    public function test_گوشیِ_سالم_علامت_نمی‌خورد(): void
    {
        // دقیقاً روی کف: مرزی که آدم اشتباه می‌کند.
        $this->sellerOn('فروشندهٔ تازه', 'Xiaomi Redmi 9', 'Android 7.0', 24);

        Livewire::actingAs($this->owner)
            ->test(StaffHandsets::class)
            ->assertDontSee('به‌روزرسانی نمی‌گیرد');
    }

    /**
     * گوشی‌ای که هرگز چیزی گزارش نکرده.
     *
     * «جا مانده» خواندنش کسی را می‌فرستد گوشیِ کاملاً سالم عوض کند.
     */
    public function test_گوشیِ_گزارش‌نداده_جامانده_خوانده_نمی‌شود(): void
    {
        $seller = $this->seller('فروشندهٔ ساکت');
        $seller->createToken('گوشی');

        Livewire::actingAs($this->owner)
            ->test(StaffHandsets::class)
            ->assertSee('نامشخص')
            ->assertDontSee('به‌روزرسانی نمی‌گیرد');
    }

    /**
     * کسی که هرگز وارد نشده هم یک ردیف است.
     *
     * «هیچ‌وقت وارد نشده» خودش جوابِ «چرا این نفر چیزی ثبت نمی‌کند»
     * است، و اگر از فهرست بیفتد بیرون آن سؤال بی‌جواب می‌ماند.
     */
    public function test_کسی_که_هرگز_وارد_نشده_هم_دیده_می‌شود(): void
    {
        $this->seller('فروشنده‌ای که وارد نشده');

        Livewire::actingAs($this->owner)
            ->test(StaffHandsets::class)
            ->assertSee('فروشنده‌ای که وارد نشده')
            ->assertSee('هیچ‌وقت وارد نشده');
    }

    public function test_کارمندِ_نانوایی_دیگر_در_این_فهرست_نیست(): void
    {
        $other = Bakery::create(['name' => 'نانوایی دیگر']);

        CurrentBakery::for($other->id, function () use ($other) {
            $stranger = User::factory()->create([
                'is_active' => true,
                'bakery_id' => $other->id,
                'name' => 'فروشندهٔ آن یکی',
            ]);
            $stranger->assignRole('seller');
            $stranger->createToken('گوشیِ آن یکی');
        });

        Livewire::actingAs($this->owner)
            ->test(StaffHandsets::class)
            ->assertDontSee('فروشندهٔ آن یکی');
    }

    public function test_عددِ_کنارِ_منو_فقط_جاماندها_را_می‌شمارد(): void
    {
        $this->actingAs($this->owner);

        $this->sellerOn('سالم', 'Xiaomi', 'Android 12', 31);
        $this->assertNull(StaffHandsets::getNavigationBadge());

        $this->sellerOn('جامانده', 'Samsung', 'Android 6.0', 23);
        $this->assertSame('1', StaffHandsets::getNavigationBadge());
    }

    // ------------------------------------------------------ کمک‌کننده‌ها

    private function seller(string $name): User
    {
        return CurrentBakery::for($this->shop->id, function () use ($name) {
            $seller = User::factory()->create([
                'is_active' => true,
                'bakery_id' => $this->shop->id,
                'name' => $name,
            ]);
            $seller->assignRole('seller');

            return $seller;
        });
    }

    private function sellerOn(string $name, string $device, string $os, int $sdk): User
    {
        $seller = $this->seller($name);

        $token = $seller->createToken($device)->accessToken;
        $token->forceFill(['os_version' => $os, 'sdk_int' => $sdk])->save();

        return $seller;
    }
}
