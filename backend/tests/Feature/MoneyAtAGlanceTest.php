<?php

namespace Tests\Feature;

use App\Filament\Widgets\MoneyAtAGlance;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DieselAllocation;
use App\Models\DieselDelivery;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Answering "how are we doing" used to mean visiting four pages and
 * holding the numbers in your head.
 */
class MoneyAtAGlanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['bread_price' => 5000, 'currency' => 'toman']);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function sale(float $amount, int $daysAgo = 0): Sale
    {
        $dough = DoughEntry::create(['user_id' => $this->seller->id, 'bag_count' => 1]);
        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'sold',
        ]);

        $sale = Sale::create([
            'chane_entry_id' => $chane->id,
            'user_id' => $this->seller->id,
            'bread_count' => 100,
            'payment_type' => 'cash',
            'amount' => $amount,
            'amount_difference' => 0,
        ]);

        if ($daysAgo > 0) {
            $sale->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        }

        return $sale;
    }

    public function test_the_widget_renders(): void
    {
        Livewire::test(MoneyAtAGlance::class)->assertOk();
    }

    public function test_it_shows_what_the_shop_took_today(): void
    {
        $this->sale(500_000);

        Livewire::test(MoneyAtAGlance::class)
            ->assertSee('فروش امروز')
            ->assertSee(Money::format(500_000));
    }

    public function test_it_shows_what_the_sellers_are_holding(): void
    {
        $this->sale(500_000);

        Livewire::test(MoneyAtAGlance::class)->assertSee('نزد فروشنده‌ها');
    }

    public function test_a_settled_shop_says_so_rather_than_showing_nothing(): void
    {
        Livewire::test(MoneyAtAGlance::class)->assertSee('همه حساب‌ها تسویه است');
    }

    public function test_the_diesel_line_says_when_no_quota_is_registered(): void
    {
        // Better an honest dash than a zero that reads as "none left".
        Livewire::test(MoneyAtAGlance::class)->assertSee('سهمیه این ماه ثبت نشده');
    }

    public function test_the_diesel_line_leads_with_the_tank(): void
    {
        DieselAllocation::create([
            'month_start' => Jalali::currentMonthRange()[0],
            'total_litres' => 1000,
        ]);
        DieselDelivery::create([
            'user_id' => $this->admin->id,
            'received_on' => now(),
            'litres' => 400,
        ]);

        // The tank is what stops the oven; the quota only says whether
        // more can be ordered, so it goes in the description.
        Livewire::test(MoneyAtAGlance::class)
            ->assertSee('گازوئیل در باک')
            ->assertSee('400')
            ->assertSee('600');
    }

    public function test_before_the_first_tanker_the_tank_is_not_called_empty(): void
    {
        DieselAllocation::create([
            'month_start' => Jalali::currentMonthRange()[0],
            'total_litres' => 1000,
        ]);

        // Nothing delivered yet, so nothing in the tank — but that is a
        // period which has not started drawing, not a shop about to run
        // dry, and raising it as one would cry wolf every month.
        Livewire::test(MoneyAtAGlance::class)
            ->assertSee('هنوز تحویلی ثبت نشده')
            ->assertDontSee('گازوئیل در باک');
    }

    public function test_it_shows_the_month_net_profit(): void
    {
        $this->sale(2_000_000);

        Livewire::test(MoneyAtAGlance::class)->assertSee('سود خالص ماه');
    }
}
