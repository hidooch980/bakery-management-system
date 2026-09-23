<?php

namespace Tests\Feature;

use App\Filament\Widgets\SellerAccountsTable;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\CurrentBakery;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ویجتِ حساب فروشنده‌ها، محدود به همین نانوایی.
 *
 * تا امروز درست درمی‌آمد ولی نه به‌خاطر فیلترِ خودش: `User` هیچ scope
 * ای ندارد، و فروشنده‌ای از نانوایی دیگر فقط وقتی اینجا می‌آمد که در
 * فروش‌های *ما* بدهی داشته باشد — که به‌خاطر scope روی `sales`
 * غیرممکن است.
 *
 * یعنی امنیتش را از یک رابطه قرض گرفته بود. یک فیلترِ پاک‌شده فاصله
 * داشت تا اینکه صفحهٔ اولِ صاحب، فهرستِ فروشنده‌های نانوایی دیگر را
 * نشان بدهد — با نامشان و با بدهی‌شان.
 */
class VidjeteFroushandeHaFaghatMalKhodemanRaMiShomaradTest extends TestCase
{
    use RefreshDatabase;

    private Bakery $ours;

    private Bakery $theirs;

    private User $ourOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->ours = Bakery::create([
            'name' => 'نانوایی ما',
            'currency' => 'toman',
            'bread_price' => 5000,
        ]);
        $this->theirs = Bakery::create([
            'name' => 'نانوایی دیگر',
            'currency' => 'toman',
            'bread_price' => 5000,
        ]);
        Money::forgetCache();

        $this->ourOwner = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $this->ours->id,
            'name' => 'مالکِ ما',
        ]);
        $this->ourOwner->assignRole('admin');

        CurrentBakery::forget();
    }

    public function test_فروشندهٔ_نانوایی_دیگر_در_صفحهٔ_ما_نمی‌آید(): void
    {
        $theirSeller = $this->sellerOwing($this->theirs, 'فروشندهٔ آن یکی');

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->ourOwner)
            ->test(SellerAccountsTable::class)
            ->assertDontSee($theirSeller->name);
    }

    public function test_فروشندهٔ_خودمان_می‌آید(): void
    {
        $ourSeller = $this->sellerOwing($this->ours, 'فروشندهٔ ما');

        // بدون این، آزمون بالا با ویجتی که هیچ‌کس را نشان نمی‌دهد هم
        // سبز می‌ماند و چیزی ثابت نمی‌کند.
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->ourOwner)
            ->test(SellerAccountsTable::class)
            ->assertSee($ourSeller->name);
    }

    // ------------------------------------------------------ کمک‌کننده‌ها

    /** فروشنده‌ای با فروشِ نقدیِ تسویه‌نشده در نانوایی داده‌شده. */
    private function sellerOwing(Bakery $bakery, string $name): User
    {
        return CurrentBakery::for($bakery->id, function () use ($bakery, $name) {
            $seller = User::factory()->create([
                'is_active' => true,
                'bakery_id' => $bakery->id,
                'name' => $name,
            ]);
            $seller->assignRole('seller');

            $dough = DoughEntry::create([
                'user_id' => $seller->id,
                'bag_count' => 1,
                'status' => 'processed',
            ]);

            $chane = ChaneEntry::create([
                'dough_entry_id' => $dough->id,
                'user_id' => $seller->id,
                'chane_count' => 100,
                'normal_weight_kg' => 0,
                'nanino_weight_kg' => 0,
                'spray_flour_kg' => 0,
                'status' => 'sold',
            ]);

            Sale::create([
                'chane_entry_id' => $chane->id,
                'user_id' => $seller->id,
                'payment_type' => 'cash',
                'bread_count' => 100,
                'amount' => 500_000,
            ]);

            return $seller;
        });
    }
}
