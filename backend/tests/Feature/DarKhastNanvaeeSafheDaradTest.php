<?php

namespace Tests\Feature;

use App\Filament\Pages\BakeryApplications;
use App\Models\Bakery;
use App\Models\BakeryApplication;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentBakery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ثبت‌نام نانوایی تازه — این بار با صفحه.
 *
 * پشتش ساخته شده بود و ردیف می‌نوشت، ولی هیچ‌کدامِ دو سرش صفحه
 * نداشت: نه فرمی که نانوای دیگر پرش کند، نه جایی که صاحبِ سامانه
 * درخواست‌ها را ببیند. یعنی عملاً وجود نداشت — تنها راهِ فرستادنِ
 * درخواست یک تماسِ API بود، و درخواست‌ها در جدولی می‌نشستند که
 * هیچ‌کس بازش نمی‌کرد.
 */
class DarKhastNanvaeeSafheDaradTest extends TestCase
{
    use RefreshDatabase;

    private Bakery $head;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        RateLimiter::clear('');

        $this->head = Bakery::create(['name' => 'نانوایی مادر', 'currency' => 'toman']);

        $this->owner = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $this->head->id,
        ]);
        $this->owner->assignRole('admin');

        CurrentBakery::forget();
    }

    // ---------------------------------------------------- درِ عمومی

    public function test_فرم_بدون_ورود_باز_می‌شود(): void
    {
        $this->get('/signup')
            ->assertOk()
            ->assertSee('نام نانوایی');
    }

    public function test_صفحهٔ_اول_به_فرم_راه_دارد(): void
    {
        // فرمی که هیچ لینکی به آن نرسد، فرمی است که کسی پیدایش
        // نمی‌کند.
        $this->get('/')->assertOk()->assertSee(route('signup'), escape: false);
    }

    public function test_پرکردن_فرم_یک_درخواست_ثبت_می‌کند(): void
    {
        $this->post('/signup', [
            'bakery_name' => 'نانوایی سنگکی مهر',
            'owner_name' => 'حسن مرادی',
            'phone' => '09120000000',
            'city' => 'زاهدان',
        ])->assertRedirect();

        $this->assertSame(1, BakeryApplication::count());
        $this->assertSame('pending', BakeryApplication::first()->status);
    }

    /**
     * همان قاعدهٔ درِ عمومی: پیام می‌گیرد و هیچ چیز نمی‌سازد.
     *
     * این مهم‌ترین چیزِ این صفحه است — دری بدون ورود روی سروری که یک
     * نانوایی واقعی را می‌گرداند.
     */
    public function test_فرم_نه_نانوایی_می‌سازد_نه_حساب_کاربری(): void
    {
        $shops = Bakery::count();
        $users = User::count();

        $this->post('/signup', [
            'bakery_name' => 'نانوایی تازه',
            'owner_name' => 'کسی',
            'phone' => '09120000001',
        ])->assertRedirect();

        $this->assertSame($shops, Bakery::count());
        $this->assertSame($users, User::count());
        $this->assertSame(0, Subscription::count());
    }

    public function test_فرمِ_ناقص_با_خطا_برمی‌گردد(): void
    {
        $this->post('/signup', ['bakery_name' => 'بدون تلفن'])
            ->assertSessionHasErrors(['owner_name', 'phone']);

        $this->assertSame(0, BakeryApplication::count());
    }

    // ------------------------------------------------ صفحهٔ صاحب

    public function test_صاحب_درخواست_را_در_پنل_می‌بیند(): void
    {
        $this->applied();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->owner)
            ->test(BakeryApplications::class)
            ->assertSee('نانوایی سنگکی مهر')
            ->assertSee('09120000009');
    }

    public function test_عددِ_کنارِ_منو_تعداد_در_انتظار_را_می‌گوید(): void
    {
        $this->actingAs($this->owner);

        $this->assertNull(BakeryApplications::getNavigationBadge());

        $this->applied();

        // درخواستی که هفته‌ها بی‌جواب بماند، همان نانوایی‌ای است که
        // جای دیگری می‌رود.
        $this->assertSame('1', BakeryApplications::getNavigationBadge());
    }

    public function test_پذیرش_نانوایی_و_اشتراکش_را_می‌سازد(): void
    {
        $application = $this->applied();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->owner)
            ->test(BakeryApplications::class)
            ->callAction('approveAction', arguments: ['id' => $application->id], data: [
                'email' => 'hassan@example.com',
                'password' => 'A-long-enough-one-42',
                'months' => 12,
            ]);

        $application = $application->fresh();

        $this->assertSame(BakeryApplication::APPROVED, $application->status);
        $this->assertNotNull($application->bakery_id);

        $term = Subscription::currentFor($application->bakery_id);
        $this->assertNotNull($term);
        $this->assertTrue($term->is_current);

        // نانوایی‌ای که مدیرش نتواند وارد شود، نانوایی‌ای نیست که باز
        // شده باشد.
        $this->post('/api/v1/login', [
            'login' => 'hassan@example.com',
            'password' => 'A-long-enough-one-42',
        ])->assertOk();
    }

    public function test_ردکردن_دلیلش_را_نگه_می‌دارد(): void
    {
        $application = $this->applied();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->owner)
            ->test(BakeryApplications::class)
            ->callAction('rejectAction', arguments: ['id' => $application->id], data: [
                'reason' => 'شمارهٔ تماس جواب نمی‌دهد',
            ]);

        $application = $application->fresh();

        $this->assertSame(BakeryApplication::REJECTED, $application->status);
        $this->assertSame('شمارهٔ تماس جواب نمی‌دهد', $application->rejection_reason);
        $this->assertSame(0, Bakery::where('name', 'نانوایی سنگکی مهر')->count());
    }

    /**
     * مدیرِ نانوایی‌ای که خودش از همین راه باز شده.
     *
     * همان اجازه‌ای را دارد که نانوایی خودش به او می‌دهد، و آن نباید
     * برای باز کردن نانوایی روی سامانهٔ کسِ دیگر کافی باشد.
     */
    public function test_مدیرِ_نانوایی_دیگر_این_صفحه_را_نمی‌بیند(): void
    {
        $other = Bakery::create(['name' => 'نانوایی دیگر']);

        $guest = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $other->id,
        ]);
        $guest->assignRole('admin');

        $this->actingAs($guest);

        $this->assertFalse(BakeryApplications::canAccess());

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get(BakeryApplications::getUrl())->assertForbidden();
    }

    // ------------------------------------------------------ کمک‌کننده‌ها

    private function applied(): BakeryApplication
    {
        return BakeryApplication::create([
            'bakery_name' => 'نانوایی سنگکی مهر',
            'owner_name' => 'حسن مرادی',
            'phone' => '09120000009',
            'status' => BakeryApplication::PENDING,
        ]);
    }
}
